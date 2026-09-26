<?php

namespace Tests\Feature\Actions\Membership;

use App\Actions\Membership\StartRenewal;
use App\Exceptions\RenewalCannotBeStartedException;
use App\Models\MembershipTerm;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesRenewals;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\MysqlTestCase;

class StartRenewalTest extends MysqlTestCase
{
    use CreatesRenewals;
    use CreatesReviewableApplications;

    public function test_every_new_registration_gets_the_first_six_months_free(): void
    {
        $membership = $this->activatedMembership();
        $introductory = $membership->terms->first();

        $this->assertSame(1, $introductory->term_no);
        $this->assertSame(MembershipTerm::KIND_INTRODUCTORY, $introductory->term_kind);
        $this->assertSame(6, $introductory->duration_months, 'The confirmed default introductory length is 6 months.');
        $this->assertSame(MembershipTerm::PAYMENT_NOT_REQUIRED, $introductory->payment_status);
    }

    public function test_no_payment_is_ever_created_for_the_introductory_term(): void
    {
        $membership = $this->activatedMembership();

        $this->assertSame(0, Payment::query()->count());
        $this->assertNull($membership->terms->first()->fee_amount, 'No £0 payment — the fee itself is NULL, not zero.');
    }

    public function test_starting_a_renewal_creates_a_pending_payment_term_and_a_pending_payment(): void
    {
        $membership = $this->renewableMembership();

        $term = app(StartRenewal::class)->handle($membership);

        $this->assertSame(2, $term->term_no);
        $this->assertSame(MembershipTerm::KIND_RENEWAL, $term->term_kind);
        $this->assertSame(MembershipTerm::STATUS_PENDING_PAYMENT, $term->status);
        $this->assertSame(MembershipTerm::PAYMENT_PENDING, $term->payment_status);
        $this->assertSame(12, $term->duration_months);
        $this->assertNull($term->starts_on);
        $this->assertNull($term->expires_on);

        $payment = Payment::query()->where('membership_term_id', $term->id)->firstOrFail();
        $this->assertSame(Payment::STATUS_PENDING, $payment->status);
        $this->assertSame('100.00', (string) $payment->amount);
        $this->assertSame('USD', $payment->currency);
        $this->assertSame(Payment::GATEWAY_MANUAL_BANK_TRANSFER, $payment->gateway);
        $this->assertSame($membership->user_id, $payment->user_id);
    }

    public function test_the_membership_number_is_unchanged_by_starting_a_renewal(): void
    {
        $membership = $this->renewableMembership();
        $originalNumber = $membership->membership_number;

        app(StartRenewal::class)->handle($membership);

        $this->assertSame($originalNumber, $membership->fresh()->membership_number);
    }

    public function test_a_second_renewal_cannot_be_started_while_one_is_pending(): void
    {
        $membership = $this->renewableMembership();
        app(StartRenewal::class)->handle($membership);

        $this->assertThrows(fn () => app(StartRenewal::class)->handle($membership->fresh()), RenewalCannotBeStartedException::class);

        $this->assertSame(2, DB::table('membership_terms')->where('membership_id', $membership->id)->count());
        $this->assertSame(1, DB::table('payments')->count());
    }

    public function test_a_renewal_cannot_start_without_an_active_plan_for_the_category(): void
    {
        $membership = $this->activatedMembership();
        $this->activeBankAccount('USD');

        $this->assertThrows(fn () => app(StartRenewal::class)->handle($membership), RenewalCannotBeStartedException::class);
        $this->assertSame(1, DB::table('membership_terms')->count(), 'Only the introductory term exists.');
        $this->assertSame(0, DB::table('payments')->count());
    }

    public function test_a_renewal_cannot_start_without_a_bank_account_for_the_plan_currency(): void
    {
        $membership = $this->activatedMembership();
        $this->activePlan($membership, ['currency' => 'GBP']);

        $this->assertThrows(fn () => app(StartRenewal::class)->handle($membership), RenewalCannotBeStartedException::class);
        $this->assertSame(0, DB::table('payments')->count());
    }

    public function test_duplicate_rapid_renewal_requests_create_only_one_payment(): void
    {
        $membership = $this->renewableMembership();

        app(StartRenewal::class)->handle($membership);
        $this->assertThrows(fn () => app(StartRenewal::class)->handle($membership->fresh()), RenewalCannotBeStartedException::class);
        $this->assertThrows(fn () => app(StartRenewal::class)->handle($membership->fresh()), RenewalCannotBeStartedException::class);

        $this->assertSame(1, DB::table('payments')->count());
        $this->assertSame(1, DB::table('membership_terms')->where('status', 'pending_payment')->count());
    }
}
