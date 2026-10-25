<?php

namespace Tests\Feature\Eshop;

use App\Actions\Membership\StartRenewal;
use App\Actions\Payments\ConfirmPayment;
use App\Actions\Payments\SubmitPaymentEvidence;
use App\Exceptions\PaymentCannotBeReviewedException;
use App\Models\Document;
use App\Models\MembershipTerm;
use App\Models\Order;
use App\Models\Payment;
use App\Notifications\Orders\OrderPaymentRejected;
use App\Notifications\Orders\OrderReceipt;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesEshopOrders;
use Tests\Concerns\CreatesRenewals;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\Concerns\CreatesUploads;
use Tests\MysqlTestCase;

class BankTransferPaymentTest extends MysqlTestCase
{
    use CreatesEshopOrders;
    use CreatesRenewals;
    use CreatesReviewableApplications;
    use CreatesUploads;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
        Notification::fake();
        $this->eshopBankAccount();
        $this->configureDeliveryCharge();
    }

    private function paymentFor(Order $order): Payment
    {
        return Payment::query()->where('order_id', $order->id)->latest('id')->firstOrFail();
    }

    private function submitAsGuest(Order $order, string $reference = 'TRX-001'): TestResponse
    {
        return $this->post(URL::signedRoute('payment.bank-transfer', $order), [
            'reference' => $reference,
            'evidence' => [$this->pdfUpload()],
        ]);
    }

    // --- submission ---------------------------------------------------------------

    public function test_a_guest_submits_a_reference_and_evidence_and_the_payment_awaits_verification(): void
    {
        $order = $this->placeEshopOrder($this->stockedProduct(40));

        $this->submitAsGuest($order)->assertRedirect()->assertSessionHas('status');

        $payment = $this->paymentFor($order);
        $this->assertSame(Payment::STATUS_PROCESSING, $payment->status);
        $this->assertSame('TRX-001', $payment->transaction_reference);
        $this->assertNotNull($payment->submitted_at);
        $this->assertSame($this->eshopBankAccount()->id, $payment->bank_account_id);
        $this->assertSame(Order::STATUS_PENDING_PAYMENT, $order->fresh()->status, 'Not confirmed until an admin verifies it.');

        $document = $payment->evidence()->sole();
        $this->assertSame(Document::KIND_PAYMENT_EVIDENCE, $document->kind);
        $this->assertSame(Document::DISK_PRIVATE, $document->disk);
        $this->assertNull($document->uploaded_by_user_id);
        Storage::disk('private')->assertExists($document->storage_path);
        Notification::assertNothingSent();
    }

    public function test_a_member_can_submit_several_evidence_files(): void
    {
        $member = $this->member();
        $order = $this->placeEshopOrder($this->stockedProduct(), $member);

        $this->actingAs($member)->post(route('payment.bank-transfer', $order), [
            'reference' => 'TRX-002',
            'evidence' => [$this->pdfUpload(), $this->pngUpload('slip.png')],
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, $this->paymentFor($order)->evidence()->where('uploaded_by_user_id', $member->id)->count());
    }

    public function test_the_reference_and_evidence_are_validated(): void
    {
        $order = $this->placeEshopOrder($this->stockedProduct());

        $this->post(URL::signedRoute('payment.bank-transfer', $order), ['reference' => '', 'evidence' => []])
            ->assertSessionHasErrors(['reference', 'evidence']);

        $this->post(URL::signedRoute('payment.bank-transfer', $order), [
            'reference' => 'TRX',
            'evidence' => [UploadedFile::fake()->create('malware.exe', 10, 'application/octet-stream')],
        ])->assertSessionHasErrors('evidence.0');

        $this->post(URL::signedRoute('payment.bank-transfer', $order), [
            'reference' => 'TRX',
            'evidence' => array_fill(0, 4, $this->pdfUpload()),
        ])->assertSessionHasErrors('evidence');

        $this->assertSame(Payment::STATUS_PENDING, $this->paymentFor($order)->status);
    }

    public function test_another_customer_cannot_submit_evidence_for_an_order(): void
    {
        $order = $this->placeEshopOrder($this->stockedProduct(), $this->member());

        $this->actingAs($this->member())->post(route('payment.bank-transfer', $order), [
            'reference' => 'TRX', 'evidence' => [$this->pdfUpload()],
        ])->assertForbidden();

        $guestOrder = $this->placeEshopOrder($this->stockedProduct());
        $this->post(route('payment.bank-transfer', $guestOrder), ['reference' => 'TRX', 'evidence' => [$this->pdfUpload()]])->assertForbidden();
    }

    public function test_evidence_cannot_be_submitted_twice_while_under_review(): void
    {
        $order = $this->placeEshopOrder($this->stockedProduct());
        $this->submitAsGuest($order);

        $this->submitAsGuest($order, 'TRX-AGAIN')->assertSessionHasErrors('payment');

        $this->assertSame('TRX-001', $this->paymentFor($order)->transaction_reference);
        $this->assertSame(1, Payment::query()->where('order_id', $order->id)->count());
    }

    // --- evidence authorisation --------------------------------------------------------

    public function test_only_payment_reviewers_can_download_order_evidence(): void
    {
        $order = $this->placeEshopOrder($this->stockedProduct());
        $this->submitAsGuest($order);
        $document = $this->paymentFor($order)->evidence()->sole();

        $this->actingAs($this->admin())->get(route('admin.documents.show', $document))->assertOk();
        $this->actingAs($this->member())->get(route('admin.documents.show', $document))->assertForbidden();
        $this->actingAs($this->editor())->get(route('admin.documents.show', $document))->assertOk(); // editors hold payments.review (approved RBAC)
        $this->actingAs($this->dev())->get(route('admin.documents.show', $document))->assertForbidden();
        auth()->logout();
        $this->get(route('admin.documents.show', $document))->assertRedirect(route('login'));
    }

    // --- admin review ---------------------------------------------------------------------

    public function test_the_admin_queue_lists_submitted_order_transfers_only(): void
    {
        $order = $this->placeEshopOrder($this->stockedProduct());
        $this->submitAsGuest($order);
        $unsubmitted = $this->placeEshopOrder($this->stockedProduct());

        $this->actingAs($this->admin())->get(route('admin.order-payments.index'))
            ->assertOk()
            ->assertSee($order->order_number)
            ->assertSee('TRX-001')
            ->assertDontSee($unsubmitted->order_number);
    }

    public function test_approval_confirms_the_order_records_the_reviewer_and_sends_one_receipt(): void
    {
        $order = $this->placeEshopOrder($this->stockedProduct());
        $this->submitAsGuest($order);
        $payment = $this->paymentFor($order);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.order-payments.approve', $payment))->assertRedirect(route('admin.order-payments.index'));
        $this->actingAs($admin)->post(route('admin.order-payments.approve', $payment))->assertRedirect(); // a repeat click is idempotent

        $payment->refresh();
        $this->assertSame(Payment::STATUS_PAID, $payment->status);
        $this->assertSame($admin->id, $payment->reviewed_by_user_id);
        $this->assertNotNull($payment->reviewed_at);
        $this->assertNotNull($payment->paid_at);
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
        Notification::assertSentOnDemandTimes(OrderReceipt::class, 1);
        $this->assertDatabaseHas('audit_logs', ['event' => 'payment.confirmed', 'subject_type' => 'payment', 'subject_id' => $payment->id, 'user_id' => $admin->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'order.paid', 'subject_type' => 'order', 'subject_id' => $order->id]);
    }

    public function test_a_transfer_without_submitted_evidence_cannot_be_approved(): void
    {
        $order = $this->placeEshopOrder($this->stockedProduct());

        $this->actingAs($this->admin())->post(route('admin.order-payments.approve', $this->paymentFor($order)))->assertSessionHasErrors('payment');

        $this->assertSame(Order::STATUS_PENDING_PAYMENT, $order->fresh()->status);
    }

    public function test_rejection_records_the_decision_notifies_and_allows_resubmission_with_history(): void
    {
        $order = $this->placeEshopOrder($this->stockedProduct());
        $this->submitAsGuest($order);
        $payment = $this->paymentFor($order);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.order-payments.reject', $payment), ['reason' => 'Amount not received'])->assertRedirect();

        $payment->refresh();
        $this->assertSame(Payment::STATUS_FAILED, $payment->status);
        $this->assertSame('Amount not received', $payment->rejection_reason);
        $this->assertSame($admin->id, $payment->reviewed_by_user_id);
        $this->assertSame(Order::STATUS_PENDING_PAYMENT, $order->fresh()->status);
        Notification::assertSentOnDemand(OrderPaymentRejected::class);

        auth()->logout();
        $this->get(URL::signedRoute('payment.show', $order))->assertSee('Amount not received')->assertSee('Submit transfer details');

        $this->submitAsGuest($order, 'TRX-002')->assertSessionHasNoErrors();
        $payment->refresh();
        $this->assertSame(Payment::STATUS_PROCESSING, $payment->status);
        $this->assertSame('TRX-002', $payment->transaction_reference);
        $this->assertSame(2, $payment->evidence()->count(), 'Earlier evidence is kept.');
        $this->assertSame(1, Payment::query()->where('order_id', $order->id)->count(), 'Resubmitted on the same attempt.');
        $this->assertDatabaseHas('audit_logs', ['event' => 'payment.rejected', 'subject_id' => $payment->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'payment.evidence_resubmitted', 'subject_id' => $payment->id]);

        $this->actingAs($admin)->post(route('admin.order-payments.approve', $payment))->assertSessionHasNoErrors();
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
    }

    public function test_a_rejection_needs_a_reason(): void
    {
        $order = $this->placeEshopOrder($this->stockedProduct());
        $this->submitAsGuest($order);

        $this->actingAs($this->admin())->post(route('admin.order-payments.reject', $this->paymentFor($order)), ['reason' => ''])->assertSessionHasErrors('reason');
    }

    public function test_review_requires_the_payments_review_permission(): void
    {
        $order = $this->placeEshopOrder($this->stockedProduct());
        $this->submitAsGuest($order);
        $payment = $this->paymentFor($order);

        foreach ([$this->member(), $this->dev()] as $user) {
            $this->actingAs($user)->get(route('admin.order-payments.index'))->assertForbidden();
            $this->actingAs($user)->post(route('admin.order-payments.approve', $payment))->assertForbidden();
            $this->actingAs($user)->post(route('admin.order-payments.reject', $payment), ['reason' => 'x'])->assertForbidden();
        }

        $this->assertSame(Payment::STATUS_PROCESSING, $payment->fresh()->status);
    }

    public function test_a_cancelled_order_cannot_be_approved(): void
    {
        $order = $this->placeEshopOrder($this->stockedProduct());
        $this->submitAsGuest($order);
        $payment = $this->paymentFor($order);
        $admin = $this->admin();

        $this->actingAs($admin)->patch(route('admin.orders.update-status', $order), ['status' => 'cancelled']);
        $this->actingAs($admin)->post(route('admin.order-payments.approve', $payment))->assertSessionHasErrors('payment');

        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
        $this->assertNotSame(Payment::STATUS_PAID, $payment->fresh()->status);
        Notification::assertNotSentTo(Notification::route('mail', $order->customer_email), OrderReceipt::class);
    }

    // --- membership separation (regression) -------------------------------------------------

    public function test_order_transfers_never_appear_in_or_pass_through_membership_review(): void
    {
        $order = $this->placeEshopOrder($this->stockedProduct());
        $this->submitAsGuest($order);
        $payment = $this->paymentFor($order);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.payments.index'))->assertOk()->assertDontSee($order->order_number)->assertDontSee('TRX-001');

        $this->actingAs($admin)->post(route('admin.payments.confirm', $payment))->assertSessionHasErrors('payment');
        $this->assertSame(Payment::STATUS_PROCESSING, $payment->fresh()->status);
        $this->assertSame(0, DB::table('membership_status_history')->count());

        $this->expectException(PaymentCannotBeReviewedException::class);
        app(ConfirmPayment::class)->handle($payment, $admin);
    }

    public function test_membership_renewal_payments_are_unaffected_and_cannot_use_order_review(): void
    {
        $membership = $this->renewableMembership();
        $term = app(StartRenewal::class)->handle($membership);
        $renewal = app(SubmitPaymentEvidence::class)->handle($term->payment, $membership->user, 'REN-1', $this->pdfUpload());
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.order-payments.index'))->assertDontSee('REN-1');
        $this->actingAs($admin)->post(route('admin.order-payments.approve', $renewal))->assertSessionHasErrors('payment');
        $this->assertSame(Payment::STATUS_PROCESSING, $renewal->fresh()->status);

        $this->actingAs($admin)->get(route('admin.payments.index'))->assertSee('REN-1');
        $this->actingAs($admin)->post(route('admin.payments.confirm', $renewal))->assertSessionHasNoErrors();
        $this->assertSame(Payment::STATUS_PAID, $renewal->fresh()->status);
        $this->assertSame(MembershipTerm::STATUS_ACTIVE, $term->fresh()->status);
    }
}
