<?php

namespace Tests\Feature\Eshop;

use App\Models\Inventory;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\PaymentReview;
use App\Notifications\Orders\OrderRefundRecorded;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesEshopOrders;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\Concerns\CreatesUploads;
use Tests\MysqlTestCase;

class RefundAndReviewQueueTest extends MysqlTestCase
{
    use CreatesEshopOrders;
    use CreatesReviewableApplications;
    use CreatesUploads;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Storage::fake('private');
        $this->eshopBankAccount();
        $this->configureDeliveryCharge();
        $this->configurePayHere();
    }

    /**
     * A PayHere order paid through a verified notification (total 390.00).
     */
    private function paidOrder(): Order
    {
        $order = $this->placeEshopOrder($this->stockedProduct(40, 5), null, 1, Order::METHOD_PAYHERE);
        $this->post(route('payments.payhere.notify'), $this->payHereNotification($order->order_number, '390.00'))->assertOk();

        return $order->fresh();
    }

    private function refund(Payment $payment, array $overrides = []): TestResponse
    {
        return $this->post(route('admin.payment-refunds.store', $payment), [
            'amount' => '390.00',
            'reason' => 'Customer cancelled before dispatch',
            'refund_reference' => 'PH-REF-001',
            'refunded_on' => now()->toDateString(),
            ...$overrides,
        ]);
    }

    // --- refunds -----------------------------------------------------------------------------

    public function test_a_full_refund_is_recorded_with_reason_reference_admin_and_original_history_intact(): void
    {
        $order = $this->paidOrder();
        $payment = $order->latestPayment();
        $paidAt = $payment->paid_at;
        $admin = $this->admin();

        $this->actingAs($admin)->refund($payment)->assertRedirect(route('admin.orders.show', $order))->assertSessionHasNoErrors();

        $refund = PaymentRefund::query()->sole();
        $this->assertSame('390.00', $refund->amount);
        $this->assertSame('PH-REF-001', $refund->refund_reference);
        $this->assertSame('Customer cancelled before dispatch', $refund->reason);
        $this->assertSame($admin->id, $refund->recorded_by_user_id);
        $this->assertSame($order->id, $refund->order_id);
        $this->assertSame($payment->id, $refund->payment_id);

        $payment->refresh();
        $this->assertSame(Payment::STATUS_REFUNDED, $payment->status);
        $this->assertSame('390.00', $payment->amount, 'The original payment amount is never changed.');
        $this->assertEquals($paidAt, $payment->paid_at);
        $this->assertSame(Order::STATUS_REFUNDED, $order->fresh()->status);
        $this->assertSame(4, Inventory::query()->value('quantity'), 'A refund never restores stock automatically.');
        $this->assertDatabaseHas('audit_logs', ['event' => 'payment.refund_recorded', 'subject_type' => 'payment_refund', 'user_id' => $admin->id]);

        $this->actingAs($admin)->get(route('admin.orders.show', $order))->assertSee('PH-REF-001')->assertSee('Customer cancelled before dispatch');
    }

    public function test_partial_refunds_accumulate_and_cannot_exceed_the_payment(): void
    {
        $order = $this->paidOrder();
        $payment = $order->latestPayment();
        $admin = $this->admin();

        $this->actingAs($admin)->refund($payment, ['amount' => '100.00', 'refund_reference' => 'R1'])->assertSessionHasNoErrors();
        $this->assertSame(Payment::STATUS_PARTIALLY_REFUNDED, $payment->fresh()->status);
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);

        $this->actingAs($admin)->refund($payment, ['amount' => '290.01', 'refund_reference' => 'R2'])->assertSessionHasErrors('refund');
        $this->actingAs($admin)->refund($payment, ['amount' => '100.00', 'refund_reference' => 'R1'])->assertSessionHasErrors('refund');
        $this->actingAs($admin)->refund($payment, ['amount' => '290.00', 'refund_reference' => 'R3'])->assertSessionHasNoErrors();

        $this->assertSame(Payment::STATUS_REFUNDED, $payment->fresh()->status);
        $this->assertSame(2, PaymentRefund::query()->count());
    }

    public function test_a_refund_after_full_refund_and_for_unpaid_payments_is_refused(): void
    {
        $order = $this->paidOrder();
        $admin = $this->admin();
        $this->actingAs($admin)->refund($order->latestPayment())->assertSessionHasNoErrors();
        $this->actingAs($admin)->refund($order->latestPayment(), ['refund_reference' => 'R9'])->assertSessionHasErrors('refund');

        $unpaid = $this->placeEshopOrder($this->stockedProduct());
        $this->actingAs($admin)->refund($unpaid->latestPayment())->assertSessionHasErrors('refund');

        $this->assertSame(1, PaymentRefund::query()->count());
    }

    public function test_refund_input_is_validated(): void
    {
        $payment = $this->paidOrder()->latestPayment();

        $this->actingAs($this->admin())->refund($payment, ['amount' => '1e2', 'reason' => '', 'refund_reference' => '', 'refunded_on' => now()->addDay()->toDateString()])
            ->assertSessionHasErrors(['amount', 'reason', 'refund_reference', 'refunded_on']);
        $this->assertSame(0, PaymentRefund::query()->count());
    }

    public function test_a_refund_of_a_late_payment_keeps_the_cancelled_order_cancelled(): void
    {
        $order = $this->placeEshopOrder($this->stockedProduct(40, 5), null, 1, Order::METHOD_PAYHERE);
        $admin = $this->admin();
        $this->actingAs($admin)->patch(route('admin.orders.update-status', $order), ['status' => 'cancelled']);
        auth()->logout();
        $this->post(route('payments.payhere.notify'), $this->payHereNotification($order->order_number, '390.00'))->assertOk();

        $this->actingAs($admin)->refund($order->latestPayment())->assertSessionHasNoErrors();

        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
        $this->assertSame(Payment::STATUS_REFUNDED, $order->latestPayment()->status);
        $this->assertSame(5, Inventory::query()->value('quantity'), 'Released once by the cancellation only.');
    }

    public function test_only_admins_can_record_refunds(): void
    {
        $order = $this->paidOrder();
        $payment = $order->latestPayment();
        $editor = $this->editor();

        foreach ([$editor, $this->member(), $this->dev()] as $user) {
            $this->actingAs($user)->refund($payment)->assertForbidden();
        }
        auth()->logout();
        $this->refund($payment)->assertRedirect(route('login'));
        $this->assertSame(0, PaymentRefund::query()->count());

        $this->actingAs($editor)->get(route('admin.orders.show', $order))->assertOk()->assertDontSee('Record a refund');
        $this->actingAs($this->admin())->get(route('admin.orders.show', $order))->assertSee('Record a refund');
        $this->actingAs($this->admin())->refund($payment)->assertSessionHasNoErrors();
        $this->assertSame(1, PaymentRefund::query()->count());
    }

    public function test_an_inactive_admin_cannot_record_refunds(): void
    {
        $payment = $this->paidOrder()->latestPayment();
        $admin = $this->admin();
        $admin->forceFill(['status' => 'suspended'])->save();

        // Stopped by the existing `active` middleware before any policy runs.
        $this->actingAs($admin)->refund($payment)->assertRedirect();
        $this->assertSame(0, PaymentRefund::query()->count());
        $this->assertSame(Payment::STATUS_PAID, $payment->fresh()->status);
    }

    public function test_editors_keep_review_and_bank_transfer_approval_permissions(): void
    {
        $editor = $this->editor();
        $order = $this->placeEshopOrder($this->stockedProduct());
        $this->post(URL::signedRoute('payment.bank-transfer', $order), ['reference' => 'TRX-ED', 'evidence' => [$this->pdfUpload()]]);

        $this->actingAs($editor)->get(route('admin.order-payments.index'))->assertOk()->assertSee('TRX-ED');
        $this->actingAs($editor)->post(route('admin.order-payments.approve', $order->latestPayment()))->assertSessionHasNoErrors();
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);

        $paid = $this->paidOrder();
        $this->post(route('payments.payhere.notify'), $this->payHereNotification($paid->order_number, '390.00', '-3'));
        $this->actingAs($editor)->get(route('admin.payment-reviews.index'))->assertOk()->assertSee('Chargeback');
        $this->actingAs($editor)->post(route('admin.payment-reviews.resolve', PaymentReview::query()->sole()), ['resolution_note' => 'Checked'])->assertSessionHasNoErrors();
    }

    // --- refund email ---------------------------------------------------------------------

    public function test_the_customer_is_emailed_once_per_recorded_refund(): void
    {
        $order = $this->paidOrder();
        $payment = $order->latestPayment();
        $admin = $this->admin();

        $this->actingAs($admin)->refund($payment, ['amount' => '100.00', 'refund_reference' => 'R-PART'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->refund($payment, ['amount' => '100.00', 'refund_reference' => 'R-PART'])->assertSessionHasErrors('refund');

        Notification::assertSentOnDemandTimes(OrderRefundRecorded::class, 1);
        Notification::assertSentOnDemand(OrderRefundRecorded::class, function (OrderRefundRecorded $notification, array $channels, AnonymousNotifiable $notifiable) use ($order): bool {
            $mail = $notification->toMail($notifiable);
            $text = implode(' ', $mail->introLines);

            return $notifiable->routes['mail'] === $order->customer_email
                && str_contains($text, 'LKR 100.00')
                && str_contains($text, 'R-PART')
                && str_contains($text, 'partial refund');
        });

        $this->actingAs($admin)->refund($payment, ['amount' => '290.00', 'refund_reference' => 'R-REST'])->assertSessionHasNoErrors();

        Notification::assertSentOnDemandTimes(OrderRefundRecorded::class, 2);
        Notification::assertSentOnDemand(OrderRefundRecorded::class, fn (OrderRefundRecorded $notification, array $channels, AnonymousNotifiable $notifiable): bool => str_contains(implode(' ', $notification->toMail($notifiable)->introLines), 'refunded in full'));
    }

    public function test_no_refund_email_is_sent_when_recording_fails(): void
    {
        $payment = $this->paidOrder()->latestPayment();

        $this->actingAs($this->admin())->refund($payment, ['amount' => '999.00'])->assertSessionHasErrors('refund');
        $this->actingAs($this->editor())->refund($payment)->assertForbidden();

        Notification::assertNotSentTo(Notification::route('mail', $payment->order->customer_email), OrderRefundRecorded::class);
        Notification::assertSentOnDemandTimes(OrderRefundRecorded::class, 0);
    }

    // --- review queue ------------------------------------------------------------------------

    public function test_duplicates_chargebacks_and_late_payments_are_distinct_items_in_the_queue(): void
    {
        $paid = $this->paidOrder();
        $this->post(route('payments.payhere.notify'), $this->payHereNotification($paid->order_number, '390.00', '2', ['payment_id' => 'OTHER']))->assertOk();
        $this->post(route('payments.payhere.notify'), $this->payHereNotification($paid->order_number, '390.00', '-3'))->assertOk();

        $late = $this->placeEshopOrder($this->stockedProduct(), null, 1, Order::METHOD_PAYHERE);
        $this->actingAs($this->admin())->patch(route('admin.orders.update-status', $late), ['status' => 'cancelled']);
        auth()->logout();
        $this->post(route('payments.payhere.notify'), $this->payHereNotification($late->order_number, '390.00'))->assertOk();

        $this->assertEqualsCanonicalizing(
            [PaymentReview::TYPE_DUPLICATE_NOTIFICATION, PaymentReview::TYPE_CHARGEBACK, PaymentReview::TYPE_LATE_PAYMENT],
            PaymentReview::query()->pluck('type')->all(),
        );

        $this->actingAs($this->admin())->get(route('admin.payment-reviews.index'))
            ->assertOk()
            ->assertSee('Repeated success notification')
            ->assertSee('Chargeback')
            ->assertSee('Payment after order closed')
            ->assertSee('OTHER')
            ->assertSee('(unverified)', false);
    }

    public function test_a_transfer_under_review_when_the_order_is_cancelled_goes_to_review(): void
    {
        $order = $this->placeEshopOrder($this->stockedProduct());
        $this->post(URL::signedRoute('payment.bank-transfer', $order), ['reference' => 'TRX-CANCEL', 'evidence' => [$this->pdfUpload()]]);

        $this->actingAs($this->admin())->patch(route('admin.orders.update-status', $order), ['status' => 'cancelled']);

        $review = PaymentReview::query()->sole();
        $this->assertSame(PaymentReview::TYPE_TRANSFER_CONFLICT, $review->type);
        $this->assertStringContainsString('TRX-CANCEL', $review->summary);
    }

    public function test_a_transfer_under_review_when_payhere_pays_the_order_goes_to_review(): void
    {
        $order = $this->placeEshopOrder($this->stockedProduct(), null, 1, Order::METHOD_PAYHERE);
        $this->post(URL::signedRoute('payment.retry', $order), ['payment_method' => 'bank_transfer']);
        $this->post(URL::signedRoute('payment.bank-transfer', $order), ['reference' => 'TRX-BOTH', 'evidence' => [$this->pdfUpload()]]);
        $transfer = $order->latestPayment();

        // The customer had in fact also completed the first (PayHere) attempt.
        $this->post(route('payments.payhere.notify'), $this->payHereNotification($order->order_number, '390.00'))->assertOk();

        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
        $this->assertSame(Payment::STATUS_CANCELLED, $transfer->fresh()->status);
        $this->assertSame(PaymentReview::TYPE_TRANSFER_CONFLICT, PaymentReview::query()->sole()->type);
        $this->actingAs($this->admin())->get(route('admin.order-payments.index'))->assertDontSee('TRX-BOTH');
    }

    public function test_approving_a_transfer_for_a_closed_order_sends_it_to_review_instead(): void
    {
        $order = $this->placeEshopOrder($this->stockedProduct());
        $this->post(URL::signedRoute('payment.bank-transfer', $order), ['reference' => 'TRX-LATE', 'evidence' => [$this->pdfUpload()]]);
        $transfer = $order->latestPayment();
        // Closed by another path while the admin's page was open.
        Order::query()->whereKey($order->id)->update(['status' => Order::STATUS_EXPIRED]);

        $this->actingAs($this->admin())->post(route('admin.order-payments.approve', $transfer))->assertSessionHasErrors('payment');

        $this->assertSame(Payment::STATUS_CANCELLED, $transfer->fresh()->status);
        $this->assertSame(Order::STATUS_EXPIRED, $order->fresh()->status);
        $this->assertSame(PaymentReview::TYPE_TRANSFER_CONFLICT, PaymentReview::query()->sole()->type);
    }

    public function test_resolving_records_the_decision_and_reviewer_without_touching_payments(): void
    {
        $paid = $this->paidOrder();
        $this->post(route('payments.payhere.notify'), $this->payHereNotification($paid->order_number, '390.00', '-3'))->assertOk();
        $review = PaymentReview::query()->sole();
        $editor = $this->editor();

        $this->actingAs($editor)->post(route('admin.payment-reviews.resolve', $review), ['resolution_note' => ''])->assertSessionHasErrors('resolution_note');
        $this->actingAs($editor)->post(route('admin.payment-reviews.resolve', $review), ['resolution_note' => 'Chargeback accepted; refund recorded'])->assertRedirect();

        $review->refresh();
        $this->assertSame(PaymentReview::STATUS_RESOLVED, $review->status);
        $this->assertSame($editor->id, $review->resolved_by_user_id);
        $this->assertNotNull($review->resolved_at);
        $this->assertSame(Payment::STATUS_PAID, $paid->latestPayment()->status);
        $this->assertDatabaseHas('audit_logs', ['event' => 'payment_review.resolved', 'subject_type' => 'payment_review', 'subject_id' => $review->id]);

        $this->actingAs($editor)->get(route('admin.payment-reviews.index', ['status' => 'resolved']))->assertSee('Chargeback accepted');
    }

    public function test_the_queue_requires_the_payment_review_permission(): void
    {
        $paid = $this->paidOrder();
        $this->post(route('payments.payhere.notify'), $this->payHereNotification($paid->order_number, '390.00', '-3'));
        $review = PaymentReview::query()->sole();

        foreach ([$this->member(), $this->dev()] as $user) {
            $this->actingAs($user)->get(route('admin.payment-reviews.index'))->assertForbidden();
            $this->actingAs($user)->post(route('admin.payment-reviews.resolve', $review), ['resolution_note' => 'x'])->assertForbidden();
        }

        $this->assertSame(PaymentReview::STATUS_OPEN, $review->fresh()->status);
    }
}
