<?php

namespace Tests\Feature\Http\Controllers\Member;

use App\Actions\Membership\ActivateMembership;
use App\Models\Membership;
use App\Models\MembershipSetting;
use App\Models\MembershipTerm;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\MysqlTestCase;

class MembershipControllerTest extends MysqlTestCase
{
    use CreatesReviewableApplications;

    /**
     * A real, fully activated membership (via the existing A4.1 action), with the
     * member's account already usable so tests can sign in as it.
     */
    private function activatedMember(array $applicationAttributes = [], string $categoryCode = 'P'): Membership
    {
        $application = $this->application('approved', $applicationAttributes, $categoryCode);
        $membership = app(ActivateMembership::class)->handle($application, $this->admin())->membership;
        $membership->user->forceFill(['status' => 'active'])->save();

        return $membership->fresh(['category', 'terms', 'user']);
    }

    private function insertTerm(Membership $membership, array $attributes): MembershipTerm
    {
        return MembershipTerm::forceCreate(array_merge([
            'membership_id' => $membership->id,
            'payment_status' => 'payment_not_required',
            'duration_months' => 12,
        ], $attributes));
    }

    private function renewalPlanId(Membership $membership): int
    {
        return DB::table('membership_plans')->insertGetId([
            'membership_category_id' => $membership->membership_category_id,
            'name' => 'Standard renewal',
            'fee_amount' => 25,
            'currency' => 'USD',
            'duration_months' => 12,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_a_member_can_view_their_own_membership(): void
    {
        $membership = $this->activatedMember(['full_name' => 'Nimal Perera']);

        $this->actingAs($membership->user)
            ->get(route('member.membership.show'))
            ->assertOk()
            ->assertSee($membership->membership_number)
            ->assertSee($membership->category->name)
            ->assertSee($membership->activated_on->format('j F Y'))
            ->assertSee('No payment required');
    }

    public function test_an_unauthenticated_visitor_is_redirected_to_login(): void
    {
        $this->get(route('member.membership.show'))->assertRedirect(route('login'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function notActiveStatuses(): array
    {
        return [
            'suspended' => ['suspended'],
            'pending setup' => ['pending_setup'],
        ];
    }

    #[DataProvider('notActiveStatuses')]
    public function test_a_member_whose_account_is_not_active_is_rejected(string $status): void
    {
        $membership = $this->activatedMember();
        $membership->user->forceFill(['status' => $status])->save();

        $this->actingAs($membership->user)
            ->get(route('member.membership.show'))
            ->assertRedirect(route('login'));
    }

    public function test_membership_ownership_cannot_be_spoofed(): void
    {
        $mine = $this->activatedMember(['full_name' => 'Nimal Perera', 'email' => 'nimal@example.test']);
        $theirs = $this->activatedMember(['full_name' => 'Kamal Silva', 'email' => 'kamal@example.test'], 'V');

        $response = $this->actingAs($mine->user)
            ->get(route('member.membership.show', ['user' => $theirs->user_id, 'membership' => $theirs->id, 'membership_id' => $theirs->id]))
            ->assertOk();

        $response->assertSee($mine->membership_number)->assertSee('Nimal Perera');
        $response->assertDontSee($theirs->membership_number)->assertDontSee('Kamal Silva');
    }

    public function test_membership_number_category_and_activation_date_are_displayed(): void
    {
        $membership = $this->activatedMember(['full_name' => 'Nimal Perera'], 'V');

        $this->actingAs($membership->user)
            ->get(route('member.membership.show'))
            ->assertOk()
            ->assertSee($membership->membership_number)
            ->assertSee($membership->category->name)
            ->assertSee($membership->category->description)
            ->assertSee($membership->activated_on->format('j F Y'));
    }

    public function test_an_active_term_within_its_date_range_is_selected_as_current(): void
    {
        $membership = $this->activatedMember();
        $term = $membership->terms->first();

        $this->actingAs($membership->user)
            ->get(route('member.membership.show'))
            ->assertOk()
            ->assertSee('Current term')
            ->assertSee($term->expires_on->format('j F Y'))
            ->assertDontSee('You do not currently have an active membership term.');
    }

    public function test_an_active_term_outside_its_date_range_is_not_treated_as_current(): void
    {
        $membership = $this->activatedMember();
        // The daily job that would flip a lapsed term to `expired` doesn't exist yet
        // (docs/architecture/04 §9), so the DB can genuinely hold status=active past
        // the term's own expiry date. This page must not present that as current.
        $membership->terms->first()->forceFill([
            'starts_on' => now()->subYear()->toDateString(),
            'expires_on' => now()->subDay()->toDateString(),
        ])->save();

        $this->actingAs($membership->user)
            ->get(route('member.membership.show'))
            ->assertOk()
            ->assertSee('Most recent term')
            ->assertSee('You do not currently have an active membership term.')
            ->assertDontSee('Current term', false);
    }

    public function test_it_falls_back_to_the_latest_term_when_none_are_currently_valid(): void
    {
        $membership = $this->activatedMember();
        $introductory = $membership->terms->first();
        $introductory->forceFill(['status' => MembershipTerm::STATUS_EXPIRED, 'expired_at' => now()])->save();

        $lapsedRenewal = $this->insertTerm($membership, [
            'term_no' => 2,
            'term_kind' => MembershipTerm::KIND_RENEWAL,
            'status' => MembershipTerm::STATUS_ACTIVE,
            'payment_status' => 'payment_confirmed',
            'membership_plan_id' => $this->renewalPlanId($membership),
            'fee_amount' => 25,
            'fee_currency' => 'USD',
            'starts_on' => now()->subYear()->toDateString(),
            'expires_on' => now()->subDay()->toDateString(),
            'activated_at' => now()->subYear(),
        ]);

        $response = $this->actingAs($membership->user)
            ->get(route('member.membership.show'))
            ->assertOk()
            ->assertSee('Most recent term')
            ->assertSee($lapsedRenewal->expires_on->format('j F Y'));

        $response->assertDontSee($introductory->expires_on->format('j F Y'));
    }

    public function test_introductory_free_period_information_uses_the_terms_own_data(): void
    {
        MembershipSetting::current();
        DB::table('membership_settings')->where('id', 1)->update(['introductory_period_months' => 3]);

        $membership = $this->activatedMember();
        $term = $membership->fresh('terms')->terms->first();

        $this->assertSame(3, $term->duration_months);

        $this->actingAs($membership->user)
            ->get(route('member.membership.show'))
            ->assertOk()
            ->assertSee('first 3 months')
            ->assertSee($term->expires_on->format('j F Y'))
            ->assertDontSee('6 months');
    }

    public function test_a_member_with_no_membership_sees_the_empty_state(): void
    {
        $this->actingAs($this->admin())
            ->get(route('member.membership.show'))
            ->assertOk()
            ->assertSee('You are not a member yet')
            ->assertSee('Apply now');
    }

    public function test_internal_and_admin_only_fields_are_never_exposed(): void
    {
        $membership = $this->activatedMember();
        $membership->forceFill(['verification_token' => 'super-secret-verification-token-value'])->save();

        $response = $this->actingAs($membership->user)
            ->get(route('member.membership.show'))
            ->assertOk();

        $response->assertDontSee('super-secret-verification-token-value');
        $response->assertDontSee('verification_token');
        $response->assertDontSee('number_sequence');
        $response->assertDontSee('number_year');
        $response->assertDontSee('is_active');
        $this->assertStringNotContainsString((string) $membership->id, $response->getContent());
        $this->assertStringNotContainsString((string) $membership->terms->first()->id, $response->getContent());
    }

    public function test_no_renewal_or_payment_action_is_presented(): void
    {
        $membership = $this->activatedMember();

        $response = $this->actingAs($membership->user)
            ->get(route('member.membership.show'))
            ->assertOk();

        $response->assertDontSee('Renew now');
        $response->assertDontSee('Pay now');
        $response->assertDontSee('Submit confirmation');
        $response->assertDontSee('digital card', false);
        $response->assertDontSee('QR', false);
        $response->assertDontSee('evidence', false);
        // The only <form> on the page is the shared layout's sign-out button.
        $this->assertSame(1, substr_count($response->getContent(), '<form'), 'The page offers no membership action a member can submit.');
    }
}
