<?php

namespace Tests\Feature\Http\Controllers\Member;

use App\Actions\Membership\StartRenewal;
use App\Actions\Payments\SubmitPaymentEvidence;
use App\Models\Payment;
use App\Notifications\Membership\RenewalPaymentInstructions;
use App\Notifications\Payments\ConfirmationSubmitted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesRenewals;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\Concerns\CreatesUploads;
use Tests\MysqlTestCase;

class RenewalControllerTest extends MysqlTestCase
{
    use CreatesRenewals;
    use CreatesReviewableApplications;
    use CreatesUploads;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
    }

    public function test_a_member_can_start_a_renewal_for_their_own_membership(): void
    {
        Notification::fake();
        $membership = $this->renewableMembership();

        $this->actingAs($membership->user)
            ->post(route('member.membership.renewal.start'))
            ->assertRedirect(route('member.membership.show'))
            ->assertSessionHas('status');

        $this->assertSame(1, DB::table('membership_terms')->where('status', 'pending_payment')->count());
        Notification::assertSentTo($membership->user, RenewalPaymentInstructions::class);
    }

    public function test_a_guest_cannot_start_a_renewal(): void
    {
        $membership = $this->renewableMembership();

        $this->post(route('member.membership.renewal.start'))->assertRedirect(route('login'));

        $this->assertSame(0, DB::table('membership_terms')->where('membership_id', $membership->id)->where('status', 'pending_payment')->count());
    }

    public function test_a_member_cannot_start_a_renewal_for_someone_elses_membership(): void
    {
        $mine = $this->renewableMembership(['email' => 'me@example.test']);
        $theirs = $this->renewableMembership(['email' => 'them@example.test'], 'V');

        // No id is ever accepted by this route, but confirm a spoofed one changes nothing.
        $this->actingAs($mine->user)
            ->post(route('member.membership.renewal.start'), ['membership_id' => $theirs->id, 'membership' => $theirs->id]);

        $this->assertSame(1, DB::table('membership_terms')->where('membership_id', $mine->id)->where('status', 'pending_payment')->count());
        $this->assertSame(0, DB::table('membership_terms')->where('membership_id', $theirs->id)->where('status', 'pending_payment')->count());
    }

    public function test_bank_transfer_instructions_are_shown_on_the_membership_page(): void
    {
        $membership = $this->renewableMembership();
        app(StartRenewal::class)->handle($membership);

        $this->actingAs($membership->user)
            ->get(route('member.membership.show'))
            ->assertOk()
            ->assertSee('Pay by bank transfer')
            ->assertSee('1234567890')
            ->assertSee('USD 100.00');
    }

    public function test_a_member_can_submit_payment_evidence_for_their_own_renewal(): void
    {
        Notification::fake();
        $membership = $this->renewableMembership();
        app(StartRenewal::class)->handle($membership);

        $this->actingAs($membership->user)
            ->post(route('member.membership.renewal.evidence'), [
                'reference' => 'REF-123',
                'evidence' => $this->pdfUpload(),
            ])
            ->assertRedirect(route('member.membership.show'))
            ->assertSessionHas('status');

        $this->assertSame(Payment::STATUS_PROCESSING, DB::table('payments')->value('status'));
        Notification::assertSentTo($membership->user, ConfirmationSubmitted::class);
    }

    public function test_a_member_with_no_pending_renewal_cannot_submit_evidence(): void
    {
        $membership = $this->renewableMembership();

        $this->actingAs($membership->user)
            ->post(route('member.membership.renewal.evidence'), [
                'reference' => 'REF-123',
                'evidence' => $this->pdfUpload(),
            ])
            ->assertSessionHasErrors('evidence');

        $this->assertSame(0, DB::table('documents')->count());
    }

    public function test_a_member_cannot_submit_evidence_against_another_members_renewal(): void
    {
        $mine = $this->renewableMembership(['email' => 'me2@example.test']);
        $theirs = $this->renewableMembership(['email' => 'them2@example.test'], 'V');
        $theirTerm = app(StartRenewal::class)->handle($theirs);

        // No id is accepted by the route; the lookup is always "my own pending renewal".
        $this->actingAs($mine->user)
            ->post(route('member.membership.renewal.evidence'), [
                'payment_id' => $theirTerm->payment->id,
                'reference' => 'REF-999',
                'evidence' => $this->pdfUpload(),
            ])
            ->assertSessionHasErrors('evidence');

        $this->assertSame(Payment::STATUS_PENDING, $theirTerm->payment->fresh()->status, 'The other member\'s payment is untouched.');
        $this->assertSame(0, DB::table('documents')->count());
    }

    public function test_a_duplicate_evidence_submission_is_rejected(): void
    {
        $membership = $this->renewableMembership();
        $term = app(StartRenewal::class)->handle($membership);
        app(SubmitPaymentEvidence::class)->handle($term->payment, $membership->user, 'REF-1', $this->pdfUpload());

        $this->actingAs($membership->user)
            ->post(route('member.membership.renewal.evidence'), [
                'reference' => 'REF-2',
                'evidence' => $this->pdfUpload(),
            ])
            ->assertSessionHasErrors('evidence');

        $this->assertSame(1, DB::table('documents')->count());
    }
}
