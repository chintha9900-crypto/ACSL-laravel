<?php

namespace Tests\Feature\Models;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Tests\MysqlTestCase;

class OrderTest extends MysqlTestCase
{
    // --- order/order-item relationships ----------------------------------

    public function test_an_order_has_many_items_and_each_item_belongs_to_the_order(): void
    {
        $order = Order::factory()->create();
        $first = OrderItem::factory()->create(['order_id' => $order->id]);
        $second = OrderItem::factory()->create(['order_id' => $order->id]);

        $this->assertCount(2, $order->items);
        $this->assertTrue($first->order->is($order));
        $this->assertTrue($second->order->is($order));
    }

    public function test_an_order_belongs_to_a_user_but_can_be_placed_by_a_guest(): void
    {
        $order = Order::factory()->guest()->create(['customer_email' => 'guest@example.test']);

        $this->assertNull($order->user_id);
        $this->assertNull($order->user);
        $this->assertSame('guest@example.test', $order->customer_email);
    }

    // --- product snapshots: historical orders don't change ----------------

    public function test_an_order_item_snapshots_the_product_name_sku_and_price(): void
    {
        $product = Product::factory()->create(['name' => 'ACI Polo Shirt', 'sku' => 'POLO-001', 'price' => 25]);
        $item = OrderItem::factory()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'sku' => $product->sku,
            'unit_price' => $product->price,
            'quantity' => 2,
            'line_total' => 50,
        ]);

        $product->update(['name' => 'ACI Polo Shirt (V2)', 'sku' => 'POLO-002', 'price' => 40]);

        $this->assertSame('ACI Polo Shirt', $item->fresh()->product_name);
        $this->assertSame('POLO-001', $item->fresh()->sku);
        $this->assertSame('25.00', $item->fresh()->unit_price);
    }

    public function test_an_order_item_survives_the_products_deletion(): void
    {
        $product = Product::factory()->create();
        $item = OrderItem::factory()->create(['product_id' => $product->id]);

        $product->delete();

        $this->assertNull($item->fresh()->product_id);
        $this->assertNotNull(OrderItem::find($item->id));
    }

    // --- order number --------------------------------------------------------

    public function test_the_order_number_must_be_unique(): void
    {
        Order::factory()->create(['order_number' => 'ORD-DUPLICATE']);

        $this->assertThrows(
            fn () => Order::factory()->create(['order_number' => 'ORD-DUPLICATE']),
            QueryException::class,
        );
    }

    // --- order/payment status vocabularies ----------------------------------

    public function test_an_orders_status_must_be_a_recognised_value(): void
    {
        $this->assertThrows(
            fn () => Order::factory()->create(['status' => 'teleported']),
            QueryException::class,
        );
    }

    public function test_an_order_items_quantity_must_be_at_least_one(): void
    {
        $this->assertThrows(
            fn () => OrderItem::factory()->create(['quantity' => 0]),
            QueryException::class,
        );
    }

    public function test_an_order_items_line_total_must_match_unit_price_times_quantity(): void
    {
        $this->assertThrows(
            fn () => OrderItem::factory()->create(['unit_price' => 10, 'quantity' => 3, 'line_total' => 999]),
            QueryException::class,
        );
    }

    public function test_a_payment_can_target_an_order(): void
    {
        $order = Order::factory()->create(['total_amount' => 100, 'currency' => 'LKR']);
        // `Payment` is deliberately guarded (written only by Actions/Payments/*
        // in production) — `forceCreate()` bypasses that for this DB-level
        // relationship test, the same way `tests/Concerns/CreatesRenewals.php`
        // already does for `MembershipPlan`/`PaymentBankAccount`.
        $payment = Payment::forceCreate([
            'user_id' => $order->user_id,
            'order_id' => $order->id,
            'gateway' => 'manual_bank_transfer',
            'idempotency_key' => 'test-key-1',
            'amount' => 100,
            'currency' => 'LKR',
            'status' => 'pending',
        ]);

        $this->assertTrue($payment->order->is($order));
        $this->assertTrue($order->payments->contains($payment));
    }
}
