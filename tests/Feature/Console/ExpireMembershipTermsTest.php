<?php

namespace Tests\Feature\Console;

use App\Actions\Membership\StartRenewal;
use App\Actions\Payments\ConfirmPayment;
use App\Actions\Payments\SubmitPaymentEvidence;
use App\Models\MembershipTerm;
use App\Notifications\Membership\Expired;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesRenewals;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\Concerns\CreatesUploads;
use Tests\MysqlTestCase;

class ExpireMembershipTermsTest extends MysqlTestCase
{
    use CreatesRenewals;
    use CreatesReviewableApplications;
    use CreatesUploads;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
    }

    public function test_a_term_past_its_expiry_date_becomes_expired(): void
    {
        $membership = $this->activatedMembership();
        $term = $membership->terms->first();
        $term->forceFill(['starts_on' => now()->subMonths(7), 'expires_on' => now()->subDay()])->save();

        $this->artisan('membership:expire-terms')->assertSuccessful();

        $fresh = $term->fresh();
        $this->assertSame(MembershipTerm::STATUS_EXPIRED, $fresh->status);
        $this->assertNotNull($fresh->expired_at);
    }

    public function test_a_term_still_within_its_dates_is_not_expired(): void
    {
        $membership = $this->activatedMembership();
        $term = $membership->terms->first();

        $this->artisan('membership:expire-terms')->assertSuccessful();

        $this->assertSame(MembershipTerm::STATUS_ACTIVE, $term->fresh()->status);
    }

    public function test_the_member_is_notified_when_their_term_expires(): void
    {
        Notification::fake();
        $membership = $this->activatedMembership();
        $term = $membership->terms->first();
        $term->forceFill(['starts_on' => now()->subMonths(7), 'expires_on' => now()->subDay()])->save();

        $this->artisan('membership:expire-terms')->assertSuccessful();

        Notification::assertSentTo($membership->user, Expired::class);
    }

    public function test_the_grace_period_is_exactly_one_month(): void
    {
        $membership = $this->activatedMembership();
        $term = $membership->terms->first();
        $expiresOn = now()->subDay()->startOfDay();
        $term->forceFill(['starts_on' => $expiresOn->copy()->subMonths(6), 'expires_on' => $expiresOn])->save();

        $this->artisan('membership:expire-terms')->assertSuccessful();

        $row = DB::table('notifications')->where('type', Expired::class)->first();
        $data = json_decode($row->data, true);
        $this->assertStringContainsString($expiresOn->copy()->addMonthsNoOverflow(1)->format('j F Y'), $data['message']);
    }

    public function test_running_the_command_twice_does_not_re_expire_or_re_notify(): void
    {
        Notification::fake();
        $membership = $this->activatedMembership();
        $term = $membership->terms->first();
        $term->forceFill(['starts_on' => now()->subMonths(7), 'expires_on' => now()->subDay()])->save();

        $this->artisan('membership:expire-terms')->assertSuccessful();
        $this->artisan('membership:expire-terms')->assertSuccessful();

        Notification::assertSentToTimes($membership->user, Expired::class, 1);
        $this->assertSame(
            1,
            DB::table('membership_status_history')->where('membership_term_id', $term->id)->where('event', 'membership.term_expired')->count()
        );
    }

    public function test_the_membership_number_is_unchanged_by_expiry(): void
    {
        $membership = $this->activatedMembership();
        $number = $membership->membership_number;
        $term = $membership->terms->first();
        $term->forceFill(['starts_on' => now()->subMonths(7), 'expires_on' => now()->subDay()])->save();

        $this->artisan('membership:expire-terms');

        $this->assertSame($number, $membership->fresh()->membership_number);
    }

    public function test_the_user_account_is_retained_not_suspended_and_can_still_sign_in(): void
    {
        $membership = $this->activatedMembership();
        // activatedMembership() goes through the real ActivateMembership action,
        // which provisions the account with password NULL (pending_setup); give
        // it a real password here so signing in below is meaningful.
        $membership->user->forceFill(['password' => 'a-Strong-Password-1'])->save();
        $term = $membership->terms->first();
        $term->forceFill(['starts_on' => now()->subMonths(7), 'expires_on' => now()->subDay()])->save();

        $this->artisan('membership:expire-terms');

        $user = $membership->user->fresh();
        $this->assertNotNull($user, 'The account is not deleted.');
        $this->assertSame('active', $user->status, 'The account is not suspended.');

        // Authentication itself is untouched by expiry — the same credentials work.
        $this->post('/login', ['email' => $user->email, 'password' => 'a-Strong-Password-1'])->assertSessionDoesntHaveErrors();
        $this->assertAuthenticatedAs($user);
    }

    public function test_membership_benefits_are_unavailable_after_expiry_the_dashboard_redirects_to_renewal(): void
    {
        $membership = $this->activatedMembership();
        $term = $membership->terms->first();
        $term->forceFill(['starts_on' => now()->subMonths(7), 'expires_on' => now()->subDay()])->save();

        $this->artisan('membership:expire-terms');

        $this->actingAs($membership->user->fresh())
            ->get(route('member.dashboard'))
            ->assertRedirect(route('member.membership.show'))
            ->assertSessionHas('warning');

        $this->assertFalse($membership->fresh()->hasCurrentTerm(), 'The underlying validity state a future card/QR system would check is false.');
    }

    public function test_renewal_during_the_grace_period_restores_access_and_current_term_state(): void
    {
        $membership = $this->renewableMembership();
        $term = $membership->terms->first();
        $term->forceFill(['starts_on' => now()->subMonths(7), 'expires_on' => now()->subDay()])->save();
        $this->artisan('membership:expire-terms');

        $this->assertFalse($membership->fresh()->hasCurrentTerm());

        $renewal = app(StartRenewal::class)->handle($membership->fresh());
        $payment = app(SubmitPaymentEvidence::class)->handle($renewal->payment, $membership->user, 'REF-1', $this->pdfUpload());
        app(ConfirmPayment::class)->handle($payment, $this->admin());

        $this->assertTrue($membership->fresh()->hasCurrentTerm(), 'Access — and a future card/QR — becomes valid again once the new term is active.');

        $this->actingAs($membership->user->fresh())
            ->get(route('member.dashboard'))
            ->assertOk();
    }

    public function test_the_membership_number_is_unchanged_after_a_grace_period_renewal(): void
    {
        $membership = $this->renewableMembership();
        $number = $membership->membership_number;
        $term = $membership->terms->first();
        $term->forceFill(['starts_on' => now()->subMonths(7), 'expires_on' => now()->subDay()])->save();
        $this->artisan('membership:expire-terms');

        $renewal = app(StartRenewal::class)->handle($membership->fresh());
        $payment = app(SubmitPaymentEvidence::class)->handle($renewal->payment, $membership->user, 'REF-1', $this->pdfUpload());
        app(ConfirmPayment::class)->handle($payment, $this->admin());

        $this->assertSame($number, $membership->fresh()->membership_number);
    }
}
