<?php

namespace Tests\Feature\Eshop;

use App\Actions\Cart\ResolveCart;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\Orders\OrderReceipt;
use App\Payments\DummyPaymentGate;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesEshopOrders;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\MysqlTestCase;

class DummyPaymentTest extends MysqlTestCase
{
    use CreatesEshopOrders;
    use CreatesReviewableApplications;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->eshopBankAccount();
        $this->configureDeliveryCharge();
    }

    private function tester(): User
    {
        return User::factory()->active()->create(['email' => self::DUMMY_TEST_EMAIL]);
    }

    private function simulatedOrder(User $tester): Order
    {
        return $this->placeEshopOrder($this->stockedProduct(), $tester, 1, Order::METHOD_DUMMY);
    }

    private function onHost(string $host, string $url): string
    {
        return preg_replace('#^https?://[^/]+#', 'http://'.$host, $url);
    }

    // --- outcomes -------------------------------------------------------------------------

    public function test_an_allowed_tester_can_check_out_with_the_dummy_method_and_the_order_is_marked_test(): void
    {
        $this->enableDummyPayments();
        $tester = $this->tester();
        $cart = Cart::factory()->create(['user_id' => $tester->id]);
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $this->stockedProduct()->id, 'quantity' => 1]);

        $this->actingAs($tester)->get(route('checkout.show'))->assertSee('Dummy / Test Payment — Development Only');
        $this->actingAs($tester)->post(route('checkout.store'), $this->checkoutDetails(['payment_method' => 'dummy']))->assertSessionHasNoErrors();

        $order = Order::query()->sole();
        $this->assertTrue($order->is_test);
        $this->assertSame(Payment::GATEWAY_DUMMY, Payment::query()->sole()->gateway);
        $this->actingAs($tester)->get(route('payment.show', $order))->assertSee('TEST ORDER')->assertSee('Simulate success');
    }

    public function test_simulated_success_confirms_through_the_normal_transition_and_is_marked_test(): void
    {
        $this->enableDummyPayments();
        $tester = $this->tester();
        $order = $this->simulatedOrder($tester);

        $this->actingAs($tester)->post(route('payment.dummy', [$order, 'success']))->assertRedirect()->assertSessionHas('status');

        $payment = Payment::query()->where('order_id', $order->id)->sole();
        $this->assertSame(Payment::STATUS_PAID, $payment->status);
        $this->assertTrue($payment->metadata['simulated']);
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['event' => 'order.paid', 'subject_id' => $order->id]);
        Notification::assertSentOnDemand(OrderReceipt::class, fn (OrderReceipt $receipt): bool => str_starts_with($receipt->toMail(new \stdClass)->subject, '[TEST]'));
        $this->actingAs($tester)->get(route('orders.receipt', $order))->assertOk()->assertSee('TEST RECEIPT');

        // Excluded from the sales report.
        $this->actingAs($this->admin())->get(route('admin.reports.eshop'))->assertOk()->assertDontSee('40.00');
    }

    public function test_simulated_failure_and_cancellation_leave_the_order_open_for_retry(): void
    {
        $this->enableDummyPayments();
        $tester = $this->tester();
        $order = $this->simulatedOrder($tester);

        $this->actingAs($tester)->post(route('payment.dummy', [$order, 'failure']))->assertRedirect();
        $this->assertSame(Payment::STATUS_FAILED, $order->latestPayment()->status);
        $this->assertSame(Order::STATUS_PENDING_PAYMENT, $order->fresh()->status);

        $this->actingAs($tester)->post(route('payment.dummy', [$order, 'success']))->assertSessionHasErrors('payment');
        $this->assertSame(Order::STATUS_PENDING_PAYMENT, $order->fresh()->status);

        $this->actingAs($tester)->post(route('payment.retry', $order), ['payment_method' => 'dummy'])->assertSessionHasNoErrors();
        $this->assertSame(2, $order->payments()->count());

        $this->actingAs($tester)->post(route('payment.dummy', [$order, 'cancel']))->assertRedirect();
        $this->assertSame(Payment::STATUS_CANCELLED, $order->latestPayment()->status);
        $this->assertSame(Order::STATUS_PENDING_PAYMENT, $order->fresh()->status);
        Notification::assertNothingSent();
    }

    public function test_a_real_order_can_never_be_completed_with_the_dummy_method(): void
    {
        $this->enableDummyPayments();
        $tester = $this->tester();
        $order = $this->placeEshopOrder($this->stockedProduct(), $tester);

        $this->actingAs($tester)->post(route('payment.dummy', [$order, 'success']))->assertSessionHasErrors('payment');
        $this->actingAs($tester)->post(route('payment.retry', $order), ['payment_method' => 'dummy'])->assertSessionHasErrors('payment_method');

        $this->assertSame(Order::STATUS_PENDING_PAYMENT, $order->fresh()->status);
        $this->assertFalse($order->fresh()->is_test);
    }

    // --- disabled / restricted ---------------------------------------------------------------

    public function test_the_dummy_adapter_is_disabled_by_default(): void
    {
        $this->assertFalse(config('payments.dummy.enabled'));

        $tester = $this->tester();
        $order = $this->simulatedOrder($tester);

        $this->actingAs($tester)->post(route('payment.dummy', [$order, 'success']))->assertNotFound();
        $this->actingAs($tester)->get(route('payment.show', $order))->assertDontSee('Simulate success');
        $this->assertSame(Order::STATUS_PENDING_PAYMENT, $order->fresh()->status);
    }

    public function test_the_dummy_adapter_is_blocked_on_the_production_host_even_if_misconfigured(): void
    {
        $tester = $this->tester();

        foreach (['aviationclub.lk', 'www.aviationclub.lk'] as $host) {
            $this->enableDummyPayments($host);
            $order = $this->simulatedOrder($tester);

            $this->actingAs($tester)->post($this->onHost($host, route('payment.dummy', [$order, 'success'])))->assertNotFound();

            $this->assertSame(Order::STATUS_PENDING_PAYMENT, $order->fresh()->status);
        }
    }

    public function test_the_dummy_adapter_is_blocked_when_app_url_is_the_production_site(): void
    {
        $this->enableDummyPayments('dev.aviationclub.lk');
        config(['app.url' => 'https://aviationclub.lk']);
        $tester = $this->tester();
        $order = $this->simulatedOrder($tester);

        $this->actingAs($tester)->post($this->onHost('dev.aviationclub.lk', route('payment.dummy', [$order, 'success'])))->assertNotFound();

        $this->assertSame(Order::STATUS_PENDING_PAYMENT, $order->fresh()->status);
    }

    public function test_the_dummy_adapter_works_on_an_allowlisted_development_host(): void
    {
        $this->enableDummyPayments('dev.aviationclub.lk');
        $tester = $this->tester();
        $order = $this->simulatedOrder($tester);

        $this->actingAs($tester)->post($this->onHost('localhost', route('payment.dummy', [$order, 'success'])))->assertNotFound();
        $this->actingAs($tester)->post($this->onHost('dev.aviationclub.lk', route('payment.dummy', [$order, 'success'])))->assertRedirect();

        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
    }

    public function test_only_allowlisted_test_accounts_may_use_it(): void
    {
        $this->enableDummyPayments();
        $tester = $this->tester();
        $order = $this->simulatedOrder($tester);
        $tester->forceFill(['email' => 'someone-else@example.test'])->save();

        $this->actingAs($tester)->post(route('payment.dummy', [$order, 'success']))->assertNotFound();

        $this->assertSame(Order::STATUS_PENDING_PAYMENT, $order->fresh()->status);
    }

    public function test_guests_never_get_the_dummy_method(): void
    {
        $this->enableDummyPayments();
        $cart = Cart::factory()->guest()->create();
        CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $this->stockedProduct()->id, 'quantity' => 1]);
        $this->withUnencryptedCookie(ResolveCart::COOKIE_NAME, $cart->guest_token);

        EncryptCookies::except(ResolveCart::COOKIE_NAME);
        $this->get(route('checkout.show'))->assertOk()->assertDontSee('value="dummy"', false);
        $this->post(route('checkout.store'), $this->checkoutDetails(['payment_method' => 'dummy', 'email' => self::DUMMY_TEST_EMAIL]))->assertSessionHasErrors('payment_method');
        EncryptCookies::flushState();
    }

    public function test_another_user_cannot_complete_a_testers_order(): void
    {
        $this->enableDummyPayments();
        $order = $this->simulatedOrder($this->tester());
        $other = User::factory()->active()->create(['email' => 'tester2@example.test']);
        config(['payments.dummy.allowed_emails' => [self::DUMMY_TEST_EMAIL, 'tester2@example.test']]);

        $this->actingAs($other)->post(route('payment.dummy', [$order, 'success']))->assertForbidden();

        $this->assertSame(Order::STATUS_PENDING_PAYMENT, $order->fresh()->status);
    }

    public function test_the_gate_requires_every_condition(): void
    {
        $gate = app(DummyPaymentGate::class);
        $tester = $this->tester();
        $request = Request::create('http://dev.aviationclub.lk/payment');
        $request->setUserResolver(fn () => $tester);

        $this->assertFalse($gate->allows($request), 'disabled');

        config(['payments.dummy.enabled' => true, 'payments.dummy.allowed_emails' => [self::DUMMY_TEST_EMAIL]]);
        $this->assertFalse($gate->allows($request), 'host not allowlisted');

        config(['payments.dummy.allowed_hosts' => ['DEV.aviationclub.lk']]);
        $this->assertTrue($gate->allows($request));

        config(['payments.dummy.allowed_emails' => []]);
        $this->assertFalse($gate->allows($request), 'email not allowlisted');
    }

    public function test_app_env_production_always_refuses_even_with_every_other_condition_met(): void
    {
        $this->enableDummyPayments('dev.aviationclub.lk');
        $tester = $this->tester();
        $order = $this->simulatedOrder($tester);
        $request = Request::create('http://dev.aviationclub.lk/payment');
        $request->setUserResolver(fn () => $tester);
        $this->assertTrue(app(DummyPaymentGate::class)->allows($request), 'Allowed outside production.');

        config(['app.env' => 'production']);

        $this->assertFalse(app(DummyPaymentGate::class)->allows($request));
        $this->actingAs($tester)->post($this->onHost('dev.aviationclub.lk', route('payment.dummy', [$order, 'success'])))->assertNotFound();
        $this->actingAs($tester)->get($this->onHost('dev.aviationclub.lk', route('checkout.show')))->assertDontSee('value="dummy"', false);
        $this->assertSame(Order::STATUS_PENDING_PAYMENT, $order->fresh()->status);
    }

    public function test_simulated_orders_never_permanently_consume_real_stock(): void
    {
        $this->enableDummyPayments();
        $tester = $this->tester();
        $product = $this->stockedProduct(40, 5);
        $paid = $this->placeEshopOrder($product, $tester, 2, Order::METHOD_DUMMY);
        $this->assertSame(3, Inventory::query()->where('product_id', $product->id)->value('quantity'), 'Reserved while the test is in progress.');

        $this->actingAs($tester)->post(route('payment.dummy', [$paid, 'success']))->assertRedirect();
        $this->assertSame(5, Inventory::query()->where('product_id', $product->id)->value('quantity'), 'Returned after simulated success.');

        $abandoned = $this->placeEshopOrder($product, $tester, 1, Order::METHOD_DUMMY);
        $this->travel(31)->minutes();
        $this->artisan('orders:expire-unpaid');
        $this->assertSame(Order::STATUS_EXPIRED, $abandoned->fresh()->status);
        $this->assertSame(5, Inventory::query()->where('product_id', $product->id)->value('quantity'), 'Returned when the test order expires.');
    }
}
