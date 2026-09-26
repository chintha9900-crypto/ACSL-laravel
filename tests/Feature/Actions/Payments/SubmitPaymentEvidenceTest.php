<?php

namespace Tests\Feature\Actions\Payments;

use App\Actions\Membership\StartRenewal;
use App\Actions\Payments\SubmitPaymentEvidence;
use App\Exceptions\PaymentCannotBeSubmittedException;
use App\Models\MembershipTerm;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesRenewals;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\Concerns\CreatesUploads;
use Tests\MysqlTestCase;

class SubmitPaymentEvidenceTest extends MysqlTestCase
{
    use CreatesRenewals;
    use CreatesReviewableApplications;
    use CreatesUploads;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
    }

    private function pendingPayment(): array
    {
        $membership = $this->renewableMembership();
        $term = app(StartRenewal::class)->handle($membership);

        return [$membership, $term->payment];
    }

    public function test_submitting_evidence_moves_the_payment_and_term_to_awaiting_review(): void
    {
        [$membership, $payment] = $this->pendingPayment();

        $updated = app(SubmitPaymentEvidence::class)->handle($payment, $membership->user, 'REF-123', $this->pdfUpload());

        $this->assertSame(Payment::STATUS_PROCESSING, $updated->status);
        $this->assertSame('REF-123', $updated->transaction_reference);
        $this->assertNotNull($updated->submitted_at);

        $term = MembershipTerm::query()->findOrFail($payment->membership_term_id);
        $this->assertSame(MembershipTerm::PAYMENT_CONFIRMATION_SUBMITTED, $term->payment_status);
        $this->assertSame(MembershipTerm::STATUS_PENDING_PAYMENT, $term->status, 'The term is not valid until confirmed.');
    }

    public function test_evidence_is_stored_on_the_private_disk_owned_by_the_member(): void
    {
        [$membership, $payment] = $this->pendingPayment();

        app(SubmitPaymentEvidence::class)->handle($payment, $membership->user, 'REF-123', $this->pdfUpload());

        $document = DB::table('documents')->where('payment_id', $payment->id)->first();
        $this->assertNotNull($document);
        $this->assertSame('payment_evidence', $document->kind);
        $this->assertSame($membership->user_id, $document->uploaded_by_user_id);
        $this->assertSame('owner_and_admin', $document->visibility);
        Storage::disk('private')->assertExists($document->storage_path);
    }

    public function test_it_belongs_to_the_specific_term_being_renewed(): void
    {
        [$membership, $payment] = $this->pendingPayment();
        $term = MembershipTerm::query()->findOrFail($payment->membership_term_id);

        app(SubmitPaymentEvidence::class)->handle($payment, $membership->user, 'REF-123', $this->pdfUpload());

        $this->assertSame($term->id, $payment->fresh()->membership_term_id);
        $this->assertSame(MembershipTerm::KIND_RENEWAL, $term->term_kind, 'Never the introductory term.');
    }

    public function test_another_members_payment_cannot_be_targeted(): void
    {
        [, $payment] = $this->pendingPayment();
        $someoneElse = User::factory()->active()->create();

        $this->assertThrows(
            fn () => app(SubmitPaymentEvidence::class)->handle($payment, $someoneElse, 'REF-123', $this->pdfUpload()),
            PaymentCannotBeSubmittedException::class
        );

        $this->assertSame(Payment::STATUS_PENDING, $payment->fresh()->status);
        $this->assertSame(0, DB::table('documents')->count());
    }

    public function test_a_duplicate_submission_is_rejected_without_creating_a_second_document(): void
    {
        [$membership, $payment] = $this->pendingPayment();

        $updated = app(SubmitPaymentEvidence::class)->handle($payment, $membership->user, 'REF-123', $this->pdfUpload());

        $this->assertThrows(
            fn () => app(SubmitPaymentEvidence::class)->handle($updated, $membership->user, 'REF-456', $this->pdfUpload()),
            PaymentCannotBeSubmittedException::class
        );

        $this->assertSame(1, DB::table('documents')->where('payment_id', $payment->id)->count());
        $this->assertSame('REF-123', $payment->fresh()->transaction_reference, 'The first submission is untouched.');
    }

    public function test_resubmission_is_allowed_after_a_rejection(): void
    {
        [$membership, $payment] = $this->pendingPayment();
        app(SubmitPaymentEvidence::class)->handle($payment, $membership->user, 'REF-123', $this->pdfUpload());
        $payment->fresh()->forceFill(['status' => Payment::STATUS_FAILED, 'failed_at' => now()])->save();

        $resubmitted = app(SubmitPaymentEvidence::class)->handle($payment->fresh(), $membership->user, 'REF-999', $this->pdfUpload());

        $this->assertSame(Payment::STATUS_PROCESSING, $resubmitted->status);
        $this->assertSame('REF-999', $resubmitted->transaction_reference);
        $this->assertSame(2, DB::table('documents')->where('payment_id', $payment->id)->count());
    }
}
