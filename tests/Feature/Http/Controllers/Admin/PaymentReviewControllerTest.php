<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Actions\Membership\StartRenewal;
use App\Actions\Payments\SubmitPaymentEvidence;
use App\Models\MembershipTerm;
use App\Models\Payment;
use App\Notifications\Payments\ConfirmationRejected;
use App\Notifications\Payments\PaymentConfirmed;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesRenewals;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\Concerns\CreatesUploads;
use Tests\MysqlTestCase;

class PaymentReviewControllerTest extends MysqlTestCase
{
    use CreatesRenewals;
    use CreatesReviewableApplications;
    use CreatesUploads;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
    }

    private function submittedPayment(): array
    {
        $membership = $this->renewableMembership();
        $term = app(StartRenewal::class)->handle($membership);
        $payment = app(SubmitPaymentEvidence::class)->handle($term->payment, $membership->user, 'REF-123', $this->pdfUpload());

        return [$membership, $payment];
    }

    public function test_an_admin_sees_the_payment_in_the_awaiting_confirmation_queue(): void
    {
        [$membership, $payment] = $this->submittedPayment();

        $this->actingAs($this->admin())
            ->get(route('admin.payments.index'))
            ->assertOk()
            ->assertSee($membership->membership_number)
            ->assertSee($membership->user->name)
            ->assertSee('REF-123');
    }

    public function test_a_pending_unsubmitted_renewal_does_not_appear_in_the_queue(): void
    {
        $membership = $this->renewableMembership();
        app(StartRenewal::class)->handle($membership);

        $this->actingAs($this->admin())
            ->get(route('admin.payments.index'))
            ->assertOk()
            ->assertDontSee($membership->membership_number);
    }

    public function test_an_admin_can_confirm_a_payment(): void
    {
        Notification::fake();
        [$membership, $payment] = $this->submittedPayment();

        $this->actingAs($this->admin())
            ->post(route('admin.payments.confirm', $payment))
            ->assertRedirect(route('admin.payments.index'))
            ->assertSessionHas('status');

        $term = MembershipTerm::query()->findOrFail($payment->membership_term_id);
        $this->assertSame(MembershipTerm::STATUS_ACTIVE, $term->status);
        $this->assertSame(Payment::STATUS_PAID, $payment->fresh()->status);
        Notification::assertSentTo($membership->user, PaymentConfirmed::class);
    }

    public function test_an_admin_can_reject_a_payment_with_a_reason(): void
    {
        Notification::fake();
        [$membership, $payment] = $this->submittedPayment();

        $this->actingAs($this->admin())
            ->post(route('admin.payments.reject', $payment), ['reason' => 'Evidence unreadable.'])
            ->assertRedirect(route('admin.payments.index'))
            ->assertSessionHas('status');

        $term = MembershipTerm::query()->findOrFail($payment->membership_term_id);
        $this->assertSame(MembershipTerm::STATUS_PENDING_PAYMENT, $term->status, 'Rejection never activates the term.');
        $this->assertSame(Payment::STATUS_FAILED, $payment->fresh()->status);
        Notification::assertSentTo($membership->user, ConfirmationRejected::class);
    }

    public function test_rejecting_requires_a_reason(): void
    {
        [, $payment] = $this->submittedPayment();

        $this->actingAs($this->admin())
            ->post(route('admin.payments.reject', $payment), [])
            ->assertSessionHasErrors('reason');

        $this->assertSame(Payment::STATUS_PROCESSING, $payment->fresh()->status);
    }

    public function test_guests_and_members_cannot_review_payments(): void
    {
        [, $payment] = $this->submittedPayment();

        $this->get(route('admin.payments.index'))->assertRedirect(route('login'));
        $this->post(route('admin.payments.confirm', $payment))->assertRedirect(route('login'));

        $member = $this->member();
        $this->actingAs($member)->get(route('admin.payments.index'))->assertForbidden();
        $this->actingAs($member)->post(route('admin.payments.confirm', $payment))->assertForbidden();

        $this->assertSame(Payment::STATUS_PROCESSING, $payment->fresh()->status);
    }

    public function test_confirming_twice_does_not_double_activate_or_double_notify(): void
    {
        Notification::fake();
        [, $payment] = $this->submittedPayment();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.payments.confirm', $payment));
        $this->actingAs($admin)
            ->post(route('admin.payments.confirm', $payment))
            ->assertSessionHasErrors('payment');

        Notification::assertSentToTimes($payment->fresh()->user, PaymentConfirmed::class, 1);
    }
}
