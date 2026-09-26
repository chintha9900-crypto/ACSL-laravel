<?php

namespace Tests\Feature\Actions\Payments;

use App\Actions\Membership\StartRenewal;
use App\Actions\Payments\ConfirmPayment;
use App\Actions\Payments\SubmitPaymentEvidence;
use App\Exceptions\PaymentCannotBeReviewedException;
use App\Models\MembershipTerm;
use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesRenewals;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\Concerns\CreatesUploads;
use Tests\MysqlTestCase;

class ConfirmPaymentTest extends MysqlTestCase
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

    public function test_confirming_activates_the_renewal_term(): void
    {
        [$membership, $payment] = $this->submittedPayment();
        $admin = $this->admin();

        $term = app(ConfirmPayment::class)->handle($payment, $admin);

        $this->assertSame(MembershipTerm::STATUS_ACTIVE, $term->status);
        $this->assertSame(MembershipTerm::PAYMENT_CONFIRMED, $term->payment_status);
        $this->assertNotNull($term->starts_on);
        $this->assertNotNull($term->expires_on);
        $this->assertNotNull($term->activated_at);

        $paid = $payment->fresh();
        $this->assertSame(Payment::STATUS_PAID, $paid->status);
        $this->assertNotNull($paid->paid_at);
        $this->assertSame($admin->id, $paid->reviewed_by_user_id);
    }

    public function test_the_membership_number_never_changes_on_confirmation(): void
    {
        [$membership, $payment] = $this->submittedPayment();
        $originalNumber = $membership->membership_number;

        app(ConfirmPayment::class)->handle($payment, $this->admin());

        $this->assertSame($originalNumber, $membership->fresh()->membership_number);
    }

    public function test_a_renewal_confirmed_while_the_introductory_term_is_still_current_starts_the_day_after_it_expires(): void
    {
        $membership = $this->renewableMembership();
        $introductory = $membership->terms->first();
        $this->travelTo($introductory->starts_on->copy()->addMonth());

        $term = app(StartRenewal::class)->handle($membership->fresh());
        $payment = app(SubmitPaymentEvidence::class)->handle($term->payment, $membership->user, 'REF-1', $this->pdfUpload());

        $confirmed = app(ConfirmPayment::class)->handle($payment, $this->admin());

        $this->assertSame($introductory->expires_on->copy()->addDay()->toDateString(), $confirmed->starts_on->toDateString());
        $this->assertSame($confirmed->starts_on->copy()->addMonthsNoOverflow(12)->subDay()->toDateString(), $confirmed->expires_on->toDateString());
    }

    public function test_a_renewal_confirmed_after_the_previous_term_has_lapsed_starts_on_the_confirmation_date(): void
    {
        $membership = $this->renewableMembership();
        $introductory = $membership->terms->first();
        $this->travelTo(Carbon::parse($introductory->expires_on)->addMonths(2)->setTime(9, 0));

        $term = app(StartRenewal::class)->handle($membership->fresh());
        $payment = app(SubmitPaymentEvidence::class)->handle($term->payment, $membership->user, 'REF-1', $this->pdfUpload());

        $confirmed = app(ConfirmPayment::class)->handle($payment, $this->admin());

        $timezone = config('membership.business_timezone');
        $expectedStart = now()->copy()->setTimezone($timezone)->startOfDay()->toDateString();
        $this->assertSame($expectedStart, $confirmed->starts_on->toDateString());
    }

    public function test_a_payment_not_awaiting_confirmation_cannot_be_confirmed(): void
    {
        $membership = $this->renewableMembership();
        $term = app(StartRenewal::class)->handle($membership);

        $this->assertThrows(fn () => app(ConfirmPayment::class)->handle($term->payment, $this->admin()), PaymentCannotBeReviewedException::class);

        $this->assertSame(MembershipTerm::STATUS_PENDING_PAYMENT, $term->fresh()->status);
    }

    public function test_confirming_the_same_payment_twice_only_activates_it_once(): void
    {
        [, $payment] = $this->submittedPayment();
        $admin = $this->admin();

        app(ConfirmPayment::class)->handle($payment, $admin);

        $this->assertThrows(fn () => app(ConfirmPayment::class)->handle($payment->fresh(), $admin), PaymentCannotBeReviewedException::class);
    }
}
