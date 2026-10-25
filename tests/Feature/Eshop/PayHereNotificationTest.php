<?php

namespace Tests\Feature\Eshop;

use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentReview;
use App\Notifications\Orders\OrderReceipt;
use App\Payments\PayHere;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesEshopOrders;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\MysqlTestCase;

class PayHereNotificationTest extends MysqlTestCase
{
    use CreatesEshopOrders;
    use CreatesReviewableApplications;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->configurePayHere();
        $this->configureDeliveryCharge();
    }

    /**
     * @return array{0: Order, 1: Payment}
     */
    private function payHereOrder(float $price = 1500): array
    {
        $order = $this->placeEshopOrder($this->stockedProduct($price), null, 1, Order::METHOD_PAYHERE);

        return [$order, Payment::query()->where('order_id', $order->id)->sole()];
    }

    private function notify(array $payload): TestResponse
    {
        // A real server-to-server POST: no session, no CSRF token.
        return $this->post(route('payments.payhere.notify'), $payload);
    }

    // --- checkout form -------------------------------------------------------------------

    public function test_the_payment_page_posts_a_correctly_hashed_sandbox_checkout_form(): void
    {
        [$order, $payment] = $this->payHereOrder(1500);
        $expectedHash = strtoupper(md5(self::PAYHERE_TEST_MERCHANT_ID.$order->order_number.'1850.00LKR'.strtoupper(md5(self::PAYHERE_TEST_SECRET))));

        $response = $this->get(URL::signedRoute('payment.show', $order))->assertOk();

        $response->assertSee('action="https://sandbox.payhere.lk/pay/checkout"', false)
            ->assertSee('name="hash" value="'.$expectedHash.'"', false)
            ->assertSee('name="order_id" value="'.$order->order_number.'"', false)
            ->assertSee('name="amount" value="1850.00"', false)
            ->assertSee('name="currency" value="LKR"', false)
            ->assertSee('name="first_name" value="Nimal"', false)
            ->assertSee('name="last_name" value="Perera"', false)
            ->assertSee('name="phone" value="+94771234567"', false)
            ->assertSee('name="city" value="Colombo"', false)
            ->assertSee('name="country" value="Sri Lanka"', false)
            ->assertSee('name="notify_url" value="'.route('payments.payhere.notify').'"', false)
            ->assertDontSee(self::PAYHERE_TEST_SECRET)
            ->assertDontSee(strtoupper(md5(self::PAYHERE_TEST_SECRET)));
    }

    public function test_returning_from_payhere_never_marks_the_order_paid(): void
    {
        [$order, $payment] = $this->payHereOrder();

        $this->get(URL::signedRoute('payment.payhere.return', $order).'&order_id='.$order->order_number.'&status_code=2')
            ->assertRedirect();

        $this->assertSame(Payment::STATUS_PENDING, $payment->fresh()->status);
        $this->assertSame(Order::STATUS_PENDING_PAYMENT, $order->fresh()->status);

        $this->assertSame($payment->metadata, $payment->fresh()->metadata, 'The return visit stores nothing.');

        // A hint against paying twice, but PayHere stays available in case the
        // payment was not actually completed.
        $this->get(URL::signedRoute('payment.show', $order))
            ->assertSee('Waiting for PayHere to confirm your payment')
            ->assertSee('sandbox.payhere.lk');

        // The hint came only from this browser's flash, so it is gone next time.
        $this->get(URL::signedRoute('payment.show', $order))->assertDontSee('Waiting for PayHere to confirm your payment');
    }

    public function test_outside_local_development_no_payhere_form_is_offered_with_http_callback_urls(): void
    {
        [$order] = $this->payHereOrder();
        $link = URL::signedRoute('payment.show', $order);
        config(['app.env' => 'staging']);
        Log::spy();

        $this->get($link)->assertOk()->assertDontSee('sandbox.payhere.lk')->assertDontSee('name="hash"', false);

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'not HTTPS'));
    }

    public function test_with_https_url_generation_the_callback_urls_sent_to_payhere_are_https(): void
    {
        [$order] = $this->payHereOrder();
        config(['app.env' => 'staging']);
        URL::forceScheme('https');

        $response = $this->get(URL::signedRoute('payment.show', $order))->assertOk();

        $response->assertSee('sandbox.payhere.lk')
            ->assertSee('name="notify_url" value="https://', false)
            ->assertSee('name="return_url" value="https://', false)
            ->assertSee('name="cancel_url" value="https://', false);
    }

    public function test_the_return_link_still_requires_order_access(): void
    {
        [$order] = $this->payHereOrder();

        $this->get(route('payment.payhere.return', $order))->assertForbidden();
        $this->get(route('payment.payhere.cancel', $order))->assertForbidden();
    }

    // --- valid notifications -----------------------------------------------------------------

    public function test_a_valid_success_notification_confirms_the_payment_and_order(): void
    {
        [$order, $payment] = $this->payHereOrder();

        $this->notify($this->payHereNotification($order->order_number, '1850.00'))->assertOk();

        $payment->refresh();
        $this->assertSame(Payment::STATUS_PAID, $payment->status);
        $this->assertSame('320025071278', $payment->transaction_reference);
        $this->assertSame('2', $payment->metadata['payhere']['status_code']);
        $this->assertSame('VISA', $payment->metadata['payhere']['unverified']['method'], 'Unsigned fields are kept only as unverified.');
        $this->assertNull($payment->reviewed_by_user_id);
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
        Notification::assertSentOnDemandTimes(OrderReceipt::class, 1);
        $this->assertDatabaseHas('audit_logs', ['event' => 'order.paid', 'subject_id' => $order->id, 'actor_type' => 'system']);
    }

    public function test_a_duplicate_success_notification_is_idempotent(): void
    {
        [$order, $payment] = $this->payHereOrder();
        $payload = $this->payHereNotification($order->order_number, '1850.00');

        $this->notify($payload)->assertOk();
        $paidAt = $payment->fresh()->paid_at;
        $this->travel(1)->minutes();
        $this->notify($payload)->assertOk();

        $this->assertEquals($paidAt, $payment->fresh()->paid_at);
        $this->assertSame(0, PaymentReview::query()->count(), 'An exact repeat is idempotent, not a conflict.');
        Notification::assertSentOnDemandTimes(OrderReceipt::class, 1);
    }

    public function test_a_late_failure_after_success_never_undoes_the_payment(): void
    {
        [$order, $payment] = $this->payHereOrder();

        $this->notify($this->payHereNotification($order->order_number, '1850.00', '2'))->assertOk();
        $this->notify($this->payHereNotification($order->order_number, '1850.00', '-2'))->assertOk();
        $this->notify($this->payHereNotification($order->order_number, '1850.00', '-1'))->assertOk();
        $this->notify($this->payHereNotification($order->order_number, '1850.00', '0'))->assertOk();

        $this->assertSame(Payment::STATUS_PAID, $payment->fresh()->status);
        $this->assertSame('2', $payment->fresh()->metadata['payhere']['status_code']);
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
    }

    public function test_a_delayed_success_after_a_failure_still_confirms(): void
    {
        [$order, $payment] = $this->payHereOrder();

        $this->notify($this->payHereNotification($order->order_number, '1850.00', '-2'))->assertOk();
        $this->assertSame(Payment::STATUS_FAILED, $payment->fresh()->status);
        $this->assertSame(Order::STATUS_PENDING_PAYMENT, $order->fresh()->status);

        $this->notify($this->payHereNotification($order->order_number, '1850.00', '2'))->assertOk();

        $this->assertSame(Payment::STATUS_PAID, $payment->fresh()->status);
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
    }

    public function test_failure_and_cancellation_leave_the_order_open_with_stock_reserved(): void
    {
        [$order, $payment] = $this->payHereOrder();
        $inventory = $order->items()->sole()->product->inventory;

        $this->notify($this->payHereNotification($order->order_number, '1850.00', '-1'))->assertOk();

        $this->assertSame(Payment::STATUS_CANCELLED, $payment->fresh()->status);
        $this->assertSame(Order::STATUS_PENDING_PAYMENT, $order->fresh()->status);
        $this->assertSame(9, $inventory->fresh()->quantity);
        Notification::assertNothingSent();
    }

    public function test_a_pending_notification_changes_nothing_but_is_recorded(): void
    {
        [$order, $payment] = $this->payHereOrder();

        $this->notify($this->payHereNotification($order->order_number, '1850.00', '0'))->assertOk();

        $this->assertSame(Payment::STATUS_PENDING, $payment->fresh()->status);
        $this->assertSame('0', $payment->fresh()->metadata['payhere']['status_code']);
    }

    public function test_a_success_for_a_cancelled_order_is_recorded_and_flagged_but_does_not_reopen_it(): void
    {
        [$order, $payment] = $this->payHereOrder();
        $this->actingAs($this->admin())->patch(route('admin.orders.update-status', $order), ['status' => 'cancelled']);
        auth()->logout();

        $this->notify($this->payHereNotification($order->order_number, '1850.00'))->assertOk();

        $payment->refresh();
        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
        $this->assertSame(Payment::STATUS_PAID, $payment->status, 'Money was taken; it is recorded.');
        $review = PaymentReview::query()->sole();
        $this->assertSame(PaymentReview::TYPE_LATE_PAYMENT, $review->type);
        $this->assertSame($payment->id, $review->payment_id);
        $this->assertNotNull($order->fresh()->stock_released_at, 'Released once on cancellation, never re-reserved.');
        Notification::assertNothingSent();
    }

    public function test_a_second_gateway_payment_for_a_paid_attempt_is_flagged(): void
    {
        [$order, $payment] = $this->payHereOrder();

        $this->notify($this->payHereNotification($order->order_number, '1850.00'))->assertOk();
        $this->notify($this->payHereNotification($order->order_number, '1850.00', '2', ['payment_id' => '999999']))->assertOk();

        $payment->refresh();
        $this->assertSame('320025071278', $payment->transaction_reference, 'An unsigned payment_id never overwrites the recorded reference.');
        $this->assertSame('320025071278', $payment->metadata['payhere']['unverified']['payment_id']);
        $review = PaymentReview::query()->sole();
        $this->assertSame(PaymentReview::TYPE_DUPLICATE_NOTIFICATION, $review->type);
        $this->assertSame(['999999'], $review->details['unverified']['reported_references']);
        Notification::assertSentOnDemandTimes(OrderReceipt::class, 1);
    }

    public function test_a_chargeback_is_flagged_for_review_without_changing_the_order(): void
    {
        [$order, $payment] = $this->payHereOrder();
        $this->notify($this->payHereNotification($order->order_number, '1850.00'))->assertOk();

        $this->notify($this->payHereNotification($order->order_number, '1850.00', '-3'))->assertOk();

        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
        $this->assertSame(Payment::STATUS_PAID, $payment->fresh()->status);
        $this->assertSame(PaymentReview::TYPE_CHARGEBACK, PaymentReview::query()->sole()->type);
    }

    // --- rejected notifications ------------------------------------------------------------------

    public function test_an_invalid_signature_is_rejected(): void
    {
        Log::spy();
        [$order, $payment] = $this->payHereOrder();

        $this->notify($this->payHereNotification($order->order_number, '1850.00', '2', ['md5sig' => strtoupper(md5('forged'))]))->assertStatus(400);

        $this->assertSame(Payment::STATUS_PENDING, $payment->fresh()->status);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => ! str_contains(json_encode($context), self::PAYHERE_TEST_SECRET) && ! array_key_exists('md5sig', $context));
    }

    public function test_a_signature_made_with_another_secret_is_rejected(): void
    {
        [$order, $payment] = $this->payHereOrder();
        $payload = $this->payHereNotification($order->order_number, '1850.00');
        $payload['md5sig'] = strtoupper(md5(self::PAYHERE_TEST_MERCHANT_ID.$order->order_number.'1850.00LKR2'.strtoupper(md5('other-secret'))));

        $this->notify($payload)->assertStatus(400);

        $this->assertSame(Payment::STATUS_PENDING, $payment->fresh()->status);
    }

    public function test_a_wrong_merchant_id_is_rejected_even_if_self_consistently_signed(): void
    {
        [$order, $payment] = $this->payHereOrder();

        $this->notify($this->payHereNotification($order->order_number, '1850.00', '2', ['merchant_id' => '1299999']))->assertStatus(400);

        $this->assertSame(Payment::STATUS_PENDING, $payment->fresh()->status);
    }

    public function test_a_tampered_status_code_breaks_the_signature(): void
    {
        [$order, $payment] = $this->payHereOrder();
        $payload = $this->payHereNotification($order->order_number, '1850.00', '-2');
        $payload['status_code'] = '2';

        $this->notify($payload)->assertStatus(400);

        $this->assertSame(Payment::STATUS_PENDING, $payment->fresh()->status);
    }

    public function test_a_wrong_amount_is_rejected_even_when_validly_signed(): void
    {
        [$order, $payment] = $this->payHereOrder(1500);

        $this->notify($this->payHereNotification($order->order_number, '15.00'))->assertStatus(400);
        $this->notify($this->payHereNotification($order->order_number, '1500.01'))->assertStatus(400);

        $this->assertSame(Payment::STATUS_PENDING, $payment->fresh()->status);
        $this->assertSame(Order::STATUS_PENDING_PAYMENT, $order->fresh()->status);
        $this->assertDatabaseHas('payment_reviews', ['type' => 'amount_mismatch', 'payment_id' => $payment->id, 'status' => 'open']);
    }

    public function test_a_currency_mismatch_is_rejected_even_when_validly_signed(): void
    {
        [$order, $payment] = $this->payHereOrder(1500);

        $this->notify($this->payHereNotification($order->order_number, '1850.00', '2', [], 'USD'))->assertStatus(400);

        $this->assertSame(Payment::STATUS_PENDING, $payment->fresh()->status);
    }

    public function test_an_unknown_order_is_rejected(): void
    {
        $this->notify($this->payHereNotification('ORD-DOESNOTEXIST', '1850.00'))->assertNotFound();
    }

    public function test_a_bank_transfer_order_cannot_be_paid_through_a_payhere_notification(): void
    {
        $this->eshopBankAccount();
        $order = $this->placeEshopOrder($this->stockedProduct(1500));

        $this->notify($this->payHereNotification($order->order_number, '1850.00'))->assertNotFound();

        $this->assertSame(Order::STATUS_PENDING_PAYMENT, $order->fresh()->status);
    }

    public function test_missing_fields_are_rejected(): void
    {
        $this->notify(['order_id' => 'ORD-X', 'status_code' => '2'])->assertStatus(400);
        $this->notify(['merchant_id' => ['array'], 'order_id' => 'x'])->assertStatus(400);
    }

    public function test_the_endpoint_is_unavailable_when_payhere_is_not_configured(): void
    {
        [$order] = $this->payHereOrder();
        config(['payments.payhere.enabled' => false]);

        $this->notify($this->payHereNotification($order->order_number, '1850.00'))->assertNotFound();
    }

    public function test_the_signature_helper_matches_payhere_documented_algorithm(): void
    {
        $payHere = app(PayHere::class);

        $this->assertTrue($payHere->hasValidSignature($this->payHereNotification('ORD-1', '10.00')));
        $this->assertSame(
            strtoupper(md5(self::PAYHERE_TEST_MERCHANT_ID.'ORD-1'.'10.00'.'LKR'.strtoupper(md5(self::PAYHERE_TEST_SECRET)))),
            $payHere->checkoutHash('ORD-1', '10.00', 'LKR'),
        );
    }
}
