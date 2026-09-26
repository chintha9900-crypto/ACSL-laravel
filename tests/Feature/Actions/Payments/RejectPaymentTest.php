<?php

namespace Tests\Feature\Actions\Payments;

use App\Actions\Membership\StartRenewal;
use App\Actions\Payments\RejectPayment;
use App\Actions\Payments\SubmitPaymentEvidence;
use App\Exceptions\PaymentCannotBeReviewedException;
use App\Models\MembershipTerm;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesRenewals;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\Concerns\CreatesUploads;
use Tests\MysqlTestCase;

class RejectPaymentTest extends MysqlTestCase
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

    public function test_rejecting_returns_the_term_to_pending_payment_without_activating_it(): void
    {
        [, $payment] = $this->submittedPayment();
        $admin = $this->admin();

        $term = app(RejectPayment::class)->handle($payment, $admin, 'Evidence unreadable.');

        $this->assertSame(MembershipTerm::STATUS_PENDING_PAYMENT, $term->status);
        $this->assertSame(MembershipTerm::PAYMENT_PENDING, $term->payment_status);
        $this->assertNull($term->starts_on);
        $this->assertNull($term->expires_on);
        $this->assertNull($term->activated_at);

        $rejected = $payment->fresh();
        $this->assertSame(Payment::STATUS_FAILED, $rejected->status);
        $this->assertSame('Evidence unreadable.', $rejected->rejection_reason);
        $this->assertSame($admin->id, $rejected->reviewed_by_user_id);
    }

    public function test_the_renewal_is_not_restarted_the_same_term_and_payment_are_reused(): void
    {
        [$membership, $payment] = $this->submittedPayment();
        $termId = $payment->membership_term_id;
        $paymentId = $payment->id;

        app(RejectPayment::class)->handle($payment, $this->admin(), 'Evidence unreadable.');

        $this->assertSame($termId, DB::table('membership_terms')->where('membership_id', $membership->id)->where('status', 'pending_payment')->value('id'));
        $this->assertSame(1, DB::table('payments')->count());
        $this->assertSame($paymentId, DB::table('payments')->first()->id);
    }

    public function test_a_payment_not_awaiting_confirmation_cannot_be_rejected(): void
    {
        $membership = $this->renewableMembership();
        $term = app(StartRenewal::class)->handle($membership);

        $this->assertThrows(fn () => app(RejectPayment::class)->handle($term->payment, $this->admin(), 'Any reason'), PaymentCannotBeReviewedException::class);
    }

    public function test_rejecting_twice_is_refused_the_second_time(): void
    {
        [$membership, $payment] = $this->submittedPayment();
        $admin = $this->admin();
        app(RejectPayment::class)->handle($payment, $admin, 'First reason');

        $this->assertThrows(fn () => app(RejectPayment::class)->handle($payment->fresh(), $admin, 'Second reason'), PaymentCannotBeReviewedException::class);

        $this->assertSame('First reason', $payment->fresh()->rejection_reason);
    }
}
