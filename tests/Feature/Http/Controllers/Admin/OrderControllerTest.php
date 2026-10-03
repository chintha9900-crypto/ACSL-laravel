<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Actions\Checkout\PlaceOrder;
use App\Actions\Payments\ConfirmOrderPayment;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\MysqlTestCase;

class OrderControllerTest extends MysqlTestCase
{
    use CreatesReviewableApplications;

    private function withStock(Product $product, int $quantity = 10): Product
    {
        Inventory::factory()->create(['product_id' => $product->id, 'quantity' => $quantity]);

        return $product;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function customer(array $overrides = []): array
    {
        return [
            'name' => 'Nimal Perera',
            'email' => 'nimal@example.test',
            'phone' => '+94 77 123 4567',
            ...$overrides,
        ];
    }

    /**
     * Places a real order through the real checkout Action, so every test
     * starts from a genuine order/order-item/payment set, never a
     * hand-built fixture that could drift from what checkout produces.
     */
    private function placeOrder(Product $product, int $quantity = 1, array $customerOverrides = []): Order
    {
        $cart = Cart::factory()->guest()->create();
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => $quantity]);

        return app(PlaceOrder::class)->handle($cart, null, $this->customer($customerOverrides));
    }

    private function confirmPayment(Order $order): void
    {
        $payment = Payment::query()->where('order_id', $order->id)->firstOrFail();
        app(ConfirmOrderPayment::class)->handle($payment);
    }

    private function nonActiveAdmin(): User
    {
        return User::factory()->create(['role' => 'admin', 'status' => 'suspended']);
    }

    // --- authorization ------------------------------------------------------

    public function test_a_guest_cannot_reach_any_admin_order_route(): void
    {
        $product = $this->withStock(Product::factory()->create());
        $order = $this->placeOrder($product);

        $this->get(route('admin.orders.index'))->assertRedirect(route('login'));
        $this->get(route('admin.orders.show', $order))->assertRedirect(route('login'));
        $this->patch(route('admin.orders.update-status', $order), ['status' => 'cancelled'])->assertRedirect(route('login'));
    }

    public function test_a_non_admin_member_cannot_access_admin_order_management(): void
    {
        $product = $this->withStock(Product::factory()->create());
        $order = $this->placeOrder($product);
        $member = $this->member();

        $this->actingAs($member)->get(route('admin.orders.index'))->assertForbidden();
        $this->actingAs($member)->get(route('admin.orders.show', $order))->assertForbidden();
        $this->actingAs($member)->patch(route('admin.orders.update-status', $order), ['status' => 'cancelled'])->assertForbidden();
    }

    /**
     * A suspended account is signed out by `EnsureUserIsActive` on its very
     * next request, before the admin policy is ever consulted — the same
     * behaviour every other admin area already relies on.
     */
    public function test_an_inactive_admin_cannot_access_admin_order_management(): void
    {
        $inactiveAdmin = $this->nonActiveAdmin();

        $this->actingAs($inactiveAdmin)->get(route('admin.orders.index'))->assertRedirect(route('login'));
    }

    public function test_unauthorized_users_cannot_view_admin_order_data_at_all(): void
    {
        $product = $this->withStock(Product::factory()->create(['name' => 'ACI Polo Shirt']));
        $order = $this->placeOrder($product);
        $member = $this->member();

        $response = $this->actingAs($member)->get(route('admin.orders.show', $order));

        $response->assertForbidden();
        $response->assertDontSee('ACI Polo Shirt');
        $response->assertDontSee($order->customer_email);
    }

    // --- listing / search / filter --------------------------------------------

    public function test_an_admin_can_list_orders(): void
    {
        $product = $this->withStock(Product::factory()->create());
        $order = $this->placeOrder($product);

        $response = $this->actingAs($this->admin())->get(route('admin.orders.index'))->assertOk();

        $response->assertSee($order->order_number);
        $response->assertSee($order->customer_name);
    }

    public function test_an_admin_can_search_by_order_number(): void
    {
        $product = $this->withStock(Product::factory()->create(), 20);
        $first = $this->placeOrder($product, 1, ['email' => 'first@example.test']);
        $second = $this->placeOrder($product, 1, ['email' => 'second@example.test']);

        $response = $this->actingAs($this->admin())
            ->get(route('admin.orders.index', ['search' => $first->order_number]))
            ->assertOk();

        $response->assertSee($first->order_number);
        $response->assertDontSee($second->order_number);
    }

    public function test_an_admin_can_search_by_customer_name_or_email(): void
    {
        $product = $this->withStock(Product::factory()->create(), 20);
        $match = $this->placeOrder($product, 1, ['name' => 'Priya Fernando', 'email' => 'priya@example.test']);
        $other = $this->placeOrder($product, 1, ['name' => 'Kasun Silva', 'email' => 'kasun@example.test']);

        $response = $this->actingAs($this->admin())
            ->get(route('admin.orders.index', ['search' => 'priya']))
            ->assertOk();

        $response->assertSee($match->order_number);
        $response->assertDontSee($other->order_number);
    }

    public function test_an_admin_can_filter_orders_by_order_status(): void
    {
        $product = $this->withStock(Product::factory()->create(), 20);
        $pending = $this->placeOrder($product, 1, ['email' => 'pending@example.test']);
        $paid = $this->placeOrder($product, 1, ['email' => 'paid@example.test']);
        $this->confirmPayment($paid);

        $response = $this->actingAs($this->admin())
            ->get(route('admin.orders.index', ['status' => 'paid']))
            ->assertOk();

        $response->assertSee($paid->order_number);
        $response->assertDontSee($pending->order_number);
    }

    public function test_an_admin_can_filter_orders_by_payment_status(): void
    {
        $product = $this->withStock(Product::factory()->create(), 20);
        $pendingPayment = $this->placeOrder($product, 1, ['email' => 'pending@example.test']);
        $paidPayment = $this->placeOrder($product, 1, ['email' => 'paid@example.test']);
        $this->confirmPayment($paidPayment);

        $response = $this->actingAs($this->admin())
            ->get(route('admin.orders.index', ['payment_status' => 'paid']))
            ->assertOk();

        $response->assertSee($paidPayment->order_number);
        $response->assertDontSee($pendingPayment->order_number);
    }

    // --- viewing an order -----------------------------------------------------

    public function test_an_admin_can_view_complete_order_details(): void
    {
        $product = $this->withStock(Product::factory()->create(['name' => 'ACI Polo Shirt', 'sku' => 'POLO-001', 'price' => 25]));
        $order = $this->placeOrder($product, 2);

        $response = $this->actingAs($this->admin())->get(route('admin.orders.show', $order))->assertOk();

        $response->assertSee($order->order_number);
        $response->assertSee($order->customer_name);
        $response->assertSee($order->customer_email);
        $response->assertSee('ACI Polo Shirt');
        $response->assertSee('POLO-001');
        $response->assertSee('25.00', false);
        $response->assertSee('50.00', false);
        $response->assertSee('pending');
    }

    // --- status transitions -----------------------------------------------------

    public function test_a_valid_status_transition_succeeds(): void
    {
        $product = $this->withStock(Product::factory()->create());
        $order = $this->placeOrder($product);
        $this->confirmPayment($order);

        $this->actingAs($this->admin())
            ->patch(route('admin.orders.update-status', $order), ['status' => 'processing'])
            ->assertRedirect(route('admin.orders.show', $order))
            ->assertSessionHas('status');

        $this->assertSame('processing', $order->fresh()->status);
    }

    public function test_an_invalid_status_transition_is_rejected(): void
    {
        $product = $this->withStock(Product::factory()->create());
        $order = $this->placeOrder($product);
        $this->confirmPayment($order);

        // paid -> delivered skips the fulfilment pipeline entirely.
        $this->actingAs($this->admin())
            ->patch(route('admin.orders.update-status', $order), ['status' => 'delivered'])
            ->assertSessionHasErrors('status');

        $this->assertSame('paid', $order->fresh()->status);
    }

    /**
     * The admin status form must never be a back door to marking an order
     * paid — `pending_payment` only has `cancelled` as an outgoing edge;
     * only a confirmed payment may ever set `paid`.
     */
    public function test_pending_payment_cannot_be_moved_directly_to_paid_from_the_admin_form(): void
    {
        $product = $this->withStock(Product::factory()->create());
        $order = $this->placeOrder($product);

        $this->actingAs($this->admin())
            ->patch(route('admin.orders.update-status', $order), ['status' => 'paid'])
            ->assertSessionHasErrors('status');

        $this->assertSame('pending_payment', $order->fresh()->status);
    }

    public function test_an_unrecognised_status_value_is_rejected_by_validation(): void
    {
        $product = $this->withStock(Product::factory()->create());
        $order = $this->placeOrder($product);
        $this->confirmPayment($order);

        $this->actingAs($this->admin())
            ->patch(route('admin.orders.update-status', $order), ['status' => 'not-a-real-status'])
            ->assertSessionHasErrors('status');
    }

    // --- historical data is protected ------------------------------------------

    public function test_updating_order_status_never_changes_order_item_snapshots(): void
    {
        $product = $this->withStock(Product::factory()->create(['name' => 'ACI Polo Shirt', 'sku' => 'POLO-001', 'price' => 25]));
        $order = $this->placeOrder($product, 2);
        $this->confirmPayment($order);
        $item = OrderItem::query()->where('order_id', $order->id)->firstOrFail();

        $this->actingAs($this->admin())->patch(route('admin.orders.update-status', $order), ['status' => 'processing']);
        $this->actingAs($this->admin())->patch(route('admin.orders.update-status', $order), ['status' => 'packed']);

        $item->refresh();
        $this->assertSame('ACI Polo Shirt', $item->product_name);
        $this->assertSame('POLO-001', $item->sku);
        $this->assertSame('25.00', $item->unit_price);
        $this->assertSame(2, $item->quantity);
        $this->assertSame('50.00', $item->line_total);
    }

    public function test_current_product_price_changes_never_alter_the_historical_order_total(): void
    {
        $product = $this->withStock(Product::factory()->create(['price' => 25]));
        $order = $this->placeOrder($product, 2);
        $this->confirmPayment($order);

        $product->update(['price' => 999]);

        $this->actingAs($this->admin())->patch(route('admin.orders.update-status', $order), ['status' => 'processing']);

        $this->assertSame('50.00', $order->fresh()->total_amount);
    }

    // --- payment status stays separate ----------------------------------------

    public function test_updating_order_status_never_changes_the_payment_status(): void
    {
        $product = $this->withStock(Product::factory()->create());
        $order = $this->placeOrder($product);
        $this->confirmPayment($order);
        $payment = Payment::query()->where('order_id', $order->id)->firstOrFail();

        $this->actingAs($this->admin())->patch(route('admin.orders.update-status', $order), ['status' => 'processing']);

        $this->assertSame(Payment::STATUS_PAID, $payment->fresh()->status, 'The admin order-status form must never touch payments.status.');
    }

    public function test_the_order_status_form_has_no_field_that_can_set_a_payment_status(): void
    {
        $product = $this->withStock(Product::factory()->create());
        $order = $this->placeOrder($product);
        $this->confirmPayment($order);
        $payment = Payment::query()->where('order_id', $order->id)->firstOrFail();

        $this->actingAs($this->admin())->patch(route('admin.orders.update-status', $order), [
            'status' => 'processing',
            'payment_status' => 'failed',
        ]);

        $this->assertSame(Payment::STATUS_PAID, $payment->fresh()->status);
    }

    // --- cancellation/refund: duplicate-execution protection -----------------

    /**
     * No inventory restoration is implemented for cancellation/refund in
     * this step (see `Actions\Orders\UpdateOrderStatus`'s own doc comment
     * and the final report) — this proves the one thing that *is*
     * implemented, the status transition itself, cannot be executed twice:
     * `cancelled`/`refunded` are terminal in `Order::TRANSITIONS`, so a
     * repeated attempt is rejected, not silently re-applied.
     */
    public function test_cancelling_an_already_cancelled_order_is_rejected_not_reapplied(): void
    {
        $product = $this->withStock(Product::factory()->create());
        $order = $this->placeOrder($product);
        $admin = $this->admin();

        $this->actingAs($admin)->patch(route('admin.orders.update-status', $order), ['status' => 'cancelled'])
            ->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $order->fresh()->status);

        $this->actingAs($admin)->patch(route('admin.orders.update-status', $order), ['status' => 'cancelled'])
            ->assertSessionHasErrors('status');

        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_refunding_an_already_refunded_order_is_rejected_not_reapplied(): void
    {
        $product = $this->withStock(Product::factory()->create());
        $order = $this->placeOrder($product);
        $this->confirmPayment($order);
        $admin = $this->admin();

        $this->actingAs($admin)->patch(route('admin.orders.update-status', $order), ['status' => 'refunded'])
            ->assertSessionHasNoErrors();
        $this->assertSame('refunded', $order->fresh()->status);

        $this->actingAs($admin)->patch(route('admin.orders.update-status', $order), ['status' => 'refunded'])
            ->assertSessionHasErrors('status');

        $this->assertSame('refunded', $order->fresh()->status);
    }
}
