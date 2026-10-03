<?php

namespace Tests\Feature\Notifications\Orders;

use App\Actions\Checkout\PlaceOrder;
use App\Actions\Payments\CancelOrderPayment;
use App\Actions\Payments\ConfirmOrderPayment;
use App\Actions\Payments\FailOrderPayment;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Notifications\Orders\OrderReceipt;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\MysqlTestCase;

/**
 * E-Shop Step 8 — the receipt email itself is exercised through the real
 * checkout → payment-confirmation path (`PlaceOrder` + `ConfirmOrderPayment`),
 * not a hand-built fixture, so these tests prove the actual trigger wiring,
 * not just that `OrderReceipt` renders in isolation.
 */
class OrderReceiptEmailTest extends MysqlTestCase
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

    private function placeOrder(Product $product, int $quantity = 1): Order
    {
        $cart = Cart::factory()->guest()->create();
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => $quantity]);

        return app(PlaceOrder::class)->handle($cart, null, $this->customer());
    }

    public function test_a_receipt_email_is_sent_after_successful_payment_confirmation(): void
    {
        Notification::fake();
        $product = $this->withStock(Product::factory()->create());
        $order = $this->placeOrder($product);
        $payment = Payment::query()->where('order_id', $order->id)->firstOrFail();

        app(ConfirmOrderPayment::class)->handle($payment);

        Notification::assertSentOnDemand(
            OrderReceipt::class,
            fn (OrderReceipt $notification, array $channels, object $notifiable): bool => $notifiable->routes['mail'] === $order->customer_email
        );
    }

    public function test_no_receipt_email_is_sent_while_the_payment_is_still_pending(): void
    {
        Notification::fake();
        $product = $this->withStock(Product::factory()->create());
        $this->placeOrder($product);

        Notification::assertNothingSent();
    }

    public function test_no_receipt_email_is_sent_for_a_failed_payment(): void
    {
        Notification::fake();
        $product = $this->withStock(Product::factory()->create());
        $order = $this->placeOrder($product);
        $payment = Payment::query()->where('order_id', $order->id)->firstOrFail();

        app(FailOrderPayment::class)->handle($payment);

        Notification::assertNothingSent();
    }

    public function test_no_receipt_email_is_sent_for_a_cancelled_payment(): void
    {
        Notification::fake();
        $product = $this->withStock(Product::factory()->create());
        $order = $this->placeOrder($product);
        $payment = Payment::query()->where('order_id', $order->id)->firstOrFail();

        app(CancelOrderPayment::class)->handle($payment);

        Notification::assertNothingSent();
    }

    public function test_a_duplicate_payment_confirmation_does_not_send_a_duplicate_receipt_email(): void
    {
        Notification::fake();
        $product = $this->withStock(Product::factory()->create());
        $order = $this->placeOrder($product);
        $payment = Payment::query()->where('order_id', $order->id)->firstOrFail();

        app(ConfirmOrderPayment::class)->handle($payment);
        app(ConfirmOrderPayment::class)->handle($payment->fresh());
        app(ConfirmOrderPayment::class)->handle($payment->fresh());

        Notification::assertCount(1);
    }

    public function test_the_receipt_email_contains_historical_order_and_item_data(): void
    {
        $product = $this->withStock(Product::factory()->create(['name' => 'ACI Polo Shirt', 'sku' => 'POLO-001', 'price' => 25]));
        $order = $this->placeOrder($product, 2);
        $payment = Payment::query()->where('order_id', $order->id)->firstOrFail();

        app(ConfirmOrderPayment::class)->handle($payment);

        // The catalogue changes after the email has already gone out — it
        // must have captured the order's own snapshot, not a live lookup.
        $product->update(['name' => 'Renamed Product', 'sku' => 'POLO-999', 'price' => 999]);

        $mailable = (new OrderReceipt($order->fresh(['items'])))->toMail((object) ['routes' => ['mail' => $order->customer_email]]);
        $html = $mailable->render();

        $this->assertStringContainsString($order->order_number, $html);
        $this->assertStringContainsString('Nimal Perera', $html);
        $this->assertStringContainsString('ACI Polo Shirt', $html);
        $this->assertStringContainsString('POLO-001', $html);
        $this->assertStringContainsString('25.00', $html);
        $this->assertStringContainsString('50.00', $html);
        $this->assertStringContainsString('Paid', $html);
        $this->assertStringNotContainsString('Renamed Product', $html);
        $this->assertStringNotContainsString('999', $html);
    }

    /**
     * No gateway credentials or secrets exist on `Order`/`OrderItem` at all
     * (E-Shop Step 1-7 never introduced any), so there is nothing for the
     * email to leak — this proves it specifically for the one field on
     * `Payment` that could plausibly contain something sensitive
     * (`transaction_reference`), which the email never even reads.
     */
    public function test_the_receipt_email_never_exposes_payment_gateway_details(): void
    {
        $product = $this->withStock(Product::factory()->create());
        $order = $this->placeOrder($product);
        $payment = Payment::query()->where('order_id', $order->id)->firstOrFail();
        $payment->forceFill(['transaction_reference' => 'secret-gateway-txn-reference-should-never-appear'])->save();

        app(ConfirmOrderPayment::class)->handle($payment);

        $mailable = (new OrderReceipt($order->fresh(['items'])))->toMail((object) ['routes' => ['mail' => $order->customer_email]]);
        $html = $mailable->render();

        $this->assertStringNotContainsString('secret-gateway-txn-reference-should-never-appear', $html);
        $this->assertStringNotContainsString('gateway', strtolower($html));
    }
}
