<?php

namespace Tests\Feature\Http\Controllers;

use App\Actions\Cart\ResolveCart;
use App\Actions\Checkout\PlaceOrder;
use App\Actions\Payments\ConfirmOrderPayment;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Payments\ManualPaymentGateway;
use App\Payments\PaymentGatewayContract;
use App\Payments\UnavailablePaymentGateway;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\MysqlTestCase;

class PaymentControllerTest extends MysqlTestCase
{
    use CreatesReviewableApplications;

    protected function setUp(): void
    {
        parent::setUp();

        EncryptCookies::except(ResolveCart::COOKIE_NAME);
    }

    protected function tearDown(): void
    {
        EncryptCookies::flushState();

        parent::tearDown();
    }

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
     * Places a real order through the real Step 6 checkout flow (as a
     * guest, by default) so every Step 7 test starts from a genuine
     * `pending_payment` order with its own `pending` payment row — never a
     * hand-built fixture that could drift from what checkout actually
     * produces.
     */
    private function placeOrder(Product $product, ?User $user = null, int $quantity = 1): Order
    {
        $cart = Cart::factory()->guest()->create();
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => $quantity]);

        return app(PlaceOrder::class)->handle($cart, $user, $this->customer());
    }

    private function signed(Order $order, string $route): string
    {
        return URL::signedRoute($route, $order);
    }

    /**
     * E-Shop Step 10.1 — `AppServiceProvider::register()` decides which
     * `PaymentGatewayContract` implementation to bind by reading
     * `config('app.env')` *at resolution time* (a plain `bind()`, not a
     * `singleton()`), so overriding just that config value before a request
     * re-resolves the gateway is enough to exercise the production path.
     * Deliberately not `$this->app->instance('env', 'production')`: that
     * would also flip `$app->runningUnitTests()`, which the framework's own
     * CSRF middleware uses to bypass token checks in tests — this helper
     * must change only the one thing this feature actually depends on.
     */
    private function simulateProductionEnvironment(): void
    {
        config(['app.env' => 'production']);
    }

    // --- payment page access / pending state ----------------------------------

    public function test_the_payment_page_shows_the_amount_and_pending_status_for_the_owner(): void
    {
        $product = $this->withStock(Product::factory()->create(['price' => 40]));
        $member = $this->member();
        $order = $this->placeOrder($product, $member);

        $response = $this->actingAs($member)->get(route('payment.show', $order))->assertOk();

        $response->assertSee('40.00', false);
        $response->assertSee('pending');
        $response->assertSee($order->order_number);
    }

    public function test_a_guest_can_reach_their_own_payment_page_via_the_signed_link(): void
    {
        $product = $this->withStock(Product::factory()->create(['price' => 20]));
        $order = $this->placeOrder($product);

        $this->get($this->signed($order, 'payment.show'))->assertOk()->assertSee($order->order_number);
    }

    /**
     * E-Shop Step 10.2 — the simulator controls themselves (not just the
     * backend they post to) must only ever be shown where they could work.
     */
    public function test_the_development_payment_simulator_is_visible_in_the_testing_environment(): void
    {
        $product = $this->withStock(Product::factory()->create());
        $member = $this->member();
        $order = $this->placeOrder($product, $member);

        $response = $this->actingAs($member)->get(route('payment.show', $order))->assertOk();

        $response->assertSee('Development payment simulator');
        $response->assertSee('Simulate Successful Payment');
    }

    public function test_the_development_payment_simulator_is_hidden_outside_local_and_testing(): void
    {
        $product = $this->withStock(Product::factory()->create());
        $member = $this->member();
        $order = $this->placeOrder($product, $member);

        $this->simulateProductionEnvironment();

        $response = $this->actingAs($member)->get(route('payment.show', $order))->assertOk();

        $response->assertDontSee('Development payment simulator');
        $response->assertDontSee('Simulate Successful Payment');
    }

    // --- customer/order authorization -----------------------------------------

    public function test_a_guest_cannot_reach_a_payment_page_without_a_valid_signature(): void
    {
        $product = $this->withStock(Product::factory()->create());
        $order = $this->placeOrder($product);

        $this->get(route('payment.show', $order))->assertForbidden();
    }

    public function test_a_member_cannot_reach_another_members_payment_page(): void
    {
        $product = $this->withStock(Product::factory()->create());
        $owner = $this->member();
        $intruder = $this->member();
        $order = $this->placeOrder($product, $owner);

        $this->actingAs($intruder)->get(route('payment.show', $order))->assertForbidden();
    }

    public function test_unauthorized_confirm_fail_and_cancel_attempts_are_blocked(): void
    {
        $product = $this->withStock(Product::factory()->create());
        $owner = $this->member();
        $intruder = $this->member();
        $order = $this->placeOrder($product, $owner);

        $this->actingAs($intruder)->post(route('payment.confirm', $order))->assertForbidden();
        $this->actingAs($intruder)->post(route('payment.fail', $order))->assertForbidden();
        $this->actingAs($intruder)->post(route('payment.cancel', $order))->assertForbidden();

        $this->assertSame(Payment::STATUS_PENDING, Payment::query()->where('order_id', $order->id)->value('status'));
    }

    // --- successful confirmation / failure / cancellation ----------------------

    public function test_a_successful_payment_confirmation_moves_the_order_out_of_pending_payment(): void
    {
        $product = $this->withStock(Product::factory()->create());
        $member = $this->member();
        $order = $this->placeOrder($product, $member);

        $this->actingAs($member)
            ->post(route('payment.confirm', $order))
            ->assertRedirect()
            ->assertSessionHas('status');

        $order->refresh();
        $payment = Payment::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame(Order::STATUS_PAID, $order->status);
        $this->assertSame(Payment::STATUS_PAID, $payment->status);
        $this->assertNotNull($payment->paid_at);
    }

    public function test_a_failed_payment_leaves_the_order_pending_for_a_retry(): void
    {
        $product = $this->withStock(Product::factory()->create());
        $member = $this->member();
        $order = $this->placeOrder($product, $member);

        $this->actingAs($member)->post(route('payment.fail', $order))->assertRedirect();

        $order->refresh();
        $payment = Payment::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame(Order::STATUS_PENDING_PAYMENT, $order->status, 'Only a confirmed payment may move the order out of pending_payment.');
        $this->assertSame(Payment::STATUS_FAILED, $payment->status);
        $this->assertNotNull($payment->failed_at);
    }

    public function test_a_cancelled_payment_attempt_leaves_the_order_pending_for_a_retry(): void
    {
        $product = $this->withStock(Product::factory()->create());
        $member = $this->member();
        $order = $this->placeOrder($product, $member);

        $this->actingAs($member)->post(route('payment.cancel', $order))->assertRedirect();

        $order->refresh();
        $payment = Payment::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame(Order::STATUS_PENDING_PAYMENT, $order->status);
        $this->assertSame(Payment::STATUS_CANCELLED, $payment->status);
    }

    // --- idempotency / duplicate callback protection ----------------------------

    public function test_confirming_an_already_paid_payment_twice_is_idempotent(): void
    {
        $product = $this->withStock(Product::factory()->create());
        $member = $this->member();
        $order = $this->placeOrder($product, $member);
        $payment = Payment::query()->where('order_id', $order->id)->firstOrFail();

        $first = app(ConfirmOrderPayment::class)->handle($payment);
        $firstPaidAt = $first->paid_at;

        $this->travel(5)->minutes();
        $second = app(ConfirmOrderPayment::class)->handle($payment->fresh());

        $this->assertSame($firstPaidAt->toDateTimeString(), $second->paid_at->toDateTimeString(), 'A duplicate confirmation must not move paid_at.');
        $this->assertSame(1, Payment::query()->where('order_id', $order->id)->count(), 'No second payment row was created.');
    }

    public function test_a_duplicate_confirm_request_over_http_does_not_create_a_duplicate_payment_or_double_pay(): void
    {
        $product = $this->withStock(Product::factory()->create());
        $member = $this->member();
        $order = $this->placeOrder($product, $member);

        $this->actingAs($member)->post(route('payment.confirm', $order));
        $this->actingAs($member)->post(route('payment.confirm', $order))->assertRedirect();

        $this->assertSame(1, Payment::query()->where('order_id', $order->id)->count());
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
    }

    public function test_a_duplicate_callback_never_deducts_stock_again(): void
    {
        $product = $this->withStock(Product::factory()->create(), 10);
        $member = $this->member();
        $order = $this->placeOrder($product, $member, 3);
        $afterCheckout = Inventory::query()->where('product_id', $product->id)->value('quantity');
        $this->assertSame(7, $afterCheckout, 'Stock was already deducted once, at order placement (Step 6).');

        $payment = Payment::query()->where('order_id', $order->id)->firstOrFail();
        app(ConfirmOrderPayment::class)->handle($payment);
        app(ConfirmOrderPayment::class)->handle($payment->fresh());
        app(ConfirmOrderPayment::class)->handle($payment->fresh());

        $this->assertSame(7, Inventory::query()->where('product_id', $product->id)->value('quantity'), 'Confirming payment (even repeatedly) must never touch inventory.');
    }

    /**
     * "No duplicate receipt" is trivially true by construction: the receipt
     * is computed on demand from the order/order_items, never persisted as
     * its own row, so there is nothing to duplicate — proven here by
     * confirming twice and checking the receipt page renders identically
     * both times, from the same single order.
     */
    public function test_a_duplicate_callback_does_not_produce_a_duplicate_receipt(): void
    {
        $product = $this->withStock(Product::factory()->create(['price' => 15]));
        $member = $this->member();
        $order = $this->placeOrder($product, $member, 2);
        $payment = Payment::query()->where('order_id', $order->id)->firstOrFail();

        app(ConfirmOrderPayment::class)->handle($payment);
        app(ConfirmOrderPayment::class)->handle($payment->fresh());

        $this->assertSame(1, Order::query()->count());
        $this->actingAs($member)->get(route('orders.receipt', $order))->assertOk()->assertSee('30.00', false);
    }

    // --- client cannot dictate the outcome --------------------------------------

    public function test_a_client_cannot_mark_an_order_paid_by_posting_a_status_field(): void
    {
        $product = $this->withStock(Product::factory()->create());
        $member = $this->member();
        $order = $this->placeOrder($product, $member);

        // There is no endpoint that reads a "status"/"paid" field from the
        // request at all — appending one as a query string to the payment
        // page has no effect, and the order stays pending.
        $this->actingAs($member)->get(route('payment.show', [$order, 'status' => 'paid', 'paid' => 1]));

        $this->assertSame(Order::STATUS_PENDING_PAYMENT, $order->fresh()->status);
    }

    public function test_a_client_supplied_amount_is_ignored_by_confirm(): void
    {
        $product = $this->withStock(Product::factory()->create(['price' => 75]));
        $member = $this->member();
        $order = $this->placeOrder($product, $member);

        $this->actingAs($member)->post(route('payment.confirm', $order), [
            'amount' => 1,
            'total_amount' => 1,
        ]);

        $payment = Payment::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame('75.00', $payment->amount, 'The confirm endpoint never reads an amount from the request.');
        $this->assertSame('75.00', $order->fresh()->total_amount);
    }

    // --- receipt -----------------------------------------------------------------

    public function test_the_receipt_is_unavailable_until_the_order_is_paid(): void
    {
        $product = $this->withStock(Product::factory()->create());
        $member = $this->member();
        $order = $this->placeOrder($product, $member);

        $this->actingAs($member)
            ->get(route('orders.receipt', $order))
            ->assertRedirect()
            ->assertSessionHas('status');
    }

    public function test_the_receipt_contains_the_historical_order_and_item_snapshots(): void
    {
        $product = $this->withStock(Product::factory()->create(['name' => 'ACI Polo Shirt', 'sku' => 'POLO-001', 'price' => 25]));
        $member = $this->member();
        $order = $this->placeOrder($product, $member, 2);
        $payment = Payment::query()->where('order_id', $order->id)->firstOrFail();
        app(ConfirmOrderPayment::class)->handle($payment);

        // The catalogue changes after payment — the receipt must still show
        // what was actually ordered and charged, not the current product.
        $product->update(['name' => 'Renamed Product', 'sku' => 'POLO-999', 'price' => 999]);

        $response = $this->actingAs($member)->get(route('orders.receipt', $order))->assertOk();

        $response->assertSee($order->order_number);
        $response->assertSee($order->customer_name);
        $response->assertSee($order->customer_email);
        $response->assertSee('ACI Polo Shirt');
        $response->assertSee('POLO-001');
        $response->assertSee('25.00', false); // unit price
        $response->assertSee('50.00', false); // line total
        $response->assertSee('50.00', false); // order total
        $response->assertSee('paid');
        $response->assertDontSee('Renamed Product');
        $response->assertDontSee('999');
    }

    // --- guest signed order/payment access -----------------------------------

    public function test_a_guest_can_view_their_own_receipt_via_the_signed_link_once_paid(): void
    {
        $product = $this->withStock(Product::factory()->create());
        $order = $this->placeOrder($product);
        $payment = Payment::query()->where('order_id', $order->id)->firstOrFail();
        app(ConfirmOrderPayment::class)->handle($payment);

        $this->get($this->signed($order, 'orders.receipt'))->assertOk()->assertSee($order->order_number);
    }

    /**
     * E-Shop Step 8 — preserves the existing signed-URL protection rather
     * than weakening it: a tampered signature and a genuinely expired one
     * must both be rejected exactly like having no signature at all.
     */
    public function test_an_invalid_or_expired_signed_receipt_link_is_blocked(): void
    {
        $product = $this->withStock(Product::factory()->create());
        $order = $this->placeOrder($product);
        $payment = Payment::query()->where('order_id', $order->id)->firstOrFail();
        app(ConfirmOrderPayment::class)->handle($payment);

        $tampered = $this->signed($order, 'orders.receipt').'-tampered';
        $this->get($tampered)->assertForbidden();

        $expired = URL::temporarySignedRoute('orders.receipt', now()->subMinute(), $order);
        $this->get($expired)->assertForbidden();
    }

    public function test_a_guest_can_confirm_their_own_payment_via_the_signed_link(): void
    {
        $product = $this->withStock(Product::factory()->create());
        $order = $this->placeOrder($product);

        $this->post($this->signed($order, 'payment.confirm'))->assertRedirect();

        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
    }

    // --- unauthorized order/payment/receipt access ------------------------------

    public function test_an_unauthorized_guest_cannot_view_another_customers_receipt(): void
    {
        $product = $this->withStock(Product::factory()->create());
        $order = $this->placeOrder($product);
        $payment = Payment::query()->where('order_id', $order->id)->firstOrFail();
        app(ConfirmOrderPayment::class)->handle($payment);

        $this->get(route('orders.receipt', $order))->assertForbidden();
    }

    public function test_a_member_cannot_view_another_members_receipt(): void
    {
        $product = $this->withStock(Product::factory()->create());
        $owner = $this->member();
        $intruder = $this->member();
        $order = $this->placeOrder($product, $owner);
        $payment = Payment::query()->where('order_id', $order->id)->firstOrFail();
        app(ConfirmOrderPayment::class)->handle($payment);

        $this->actingAs($intruder)->get(route('orders.receipt', $order))->assertForbidden();
    }

    // --- E-Shop Step 10.1 — manual payment simulator is local/testing only -----

    public function test_the_manual_payment_gateway_is_bound_in_the_testing_environment(): void
    {
        $this->assertInstanceOf(ManualPaymentGateway::class, app(PaymentGatewayContract::class));
    }

    public function test_manual_payment_confirmation_still_works_in_the_testing_environment(): void
    {
        $product = $this->withStock(Product::factory()->create());
        $member = $this->member();
        $order = $this->placeOrder($product, $member);

        $this->actingAs($member)->post(route('payment.confirm', $order))->assertRedirect();

        $order->refresh();
        $this->assertSame(Order::STATUS_PAID, $order->status);
        $this->assertSame(Payment::STATUS_PAID, Payment::query()->where('order_id', $order->id)->value('status'));
    }

    public function test_the_unavailable_payment_gateway_is_bound_outside_local_and_testing(): void
    {
        $this->simulateProductionEnvironment();

        $this->assertInstanceOf(UnavailablePaymentGateway::class, app(PaymentGatewayContract::class));
    }

    public function test_production_cannot_mark_an_order_paid_through_the_simulator(): void
    {
        $product = $this->withStock(Product::factory()->create());
        $member = $this->member();
        $order = $this->placeOrder($product, $member);

        $this->simulateProductionEnvironment();

        $this->actingAs($member)->post(route('payment.confirm', $order))->assertNotFound();

        $order->refresh();
        $this->assertSame(Order::STATUS_PENDING_PAYMENT, $order->status);
        $this->assertSame(Payment::STATUS_PENDING, Payment::query()->where('order_id', $order->id)->value('status'));
    }

    public function test_production_cannot_mark_an_order_failed_through_the_simulator(): void
    {
        $product = $this->withStock(Product::factory()->create());
        $member = $this->member();
        $order = $this->placeOrder($product, $member);

        $this->simulateProductionEnvironment();

        $this->actingAs($member)->post(route('payment.fail', $order))->assertNotFound();

        $this->assertSame(Payment::STATUS_PENDING, Payment::query()->where('order_id', $order->id)->value('status'));
    }

    public function test_production_cannot_cancel_a_payment_through_the_simulator(): void
    {
        $product = $this->withStock(Product::factory()->create());
        $member = $this->member();
        $order = $this->placeOrder($product, $member);

        $this->simulateProductionEnvironment();

        $this->actingAs($member)->post(route('payment.cancel', $order))->assertNotFound();

        $this->assertSame(Payment::STATUS_PENDING, Payment::query()->where('order_id', $order->id)->value('status'));
    }

    /**
     * Authorization still runs before the gateway is ever touched: an
     * intruder gets the same 403 in production as in testing, never a 404
     * that would leak whether the order even has a payment to act on.
     */
    public function test_payment_authorization_is_checked_before_the_production_gateway_guard(): void
    {
        $product = $this->withStock(Product::factory()->create());
        $owner = $this->member();
        $intruder = $this->member();
        $order = $this->placeOrder($product, $owner);

        $this->simulateProductionEnvironment();

        $this->actingAs($intruder)->post(route('payment.confirm', $order))->assertForbidden();
        $this->actingAs($intruder)->post(route('payment.fail', $order))->assertForbidden();
        $this->actingAs($intruder)->post(route('payment.cancel', $order))->assertForbidden();
    }

    /**
     * The idempotency guard lives in `ConfirmOrderPayment`, called only from
     * `ManualPaymentGateway` — it is untouched by Step 10.1, and remains
     * reachable (and still idempotent) through the gateway binding in the
     * environments where the simulator is allowed to run at all.
     */
    public function test_idempotency_is_unaffected_by_the_environment_gated_binding(): void
    {
        $product = $this->withStock(Product::factory()->create());
        $member = $this->member();
        $order = $this->placeOrder($product, $member);

        $this->actingAs($member)->post(route('payment.confirm', $order))->assertRedirect();
        $this->actingAs($member)->post(route('payment.confirm', $order))->assertRedirect();

        $this->assertSame(1, Payment::query()->where('order_id', $order->id)->count());
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
    }
}
