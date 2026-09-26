<?php

namespace Tests\Feature\Http\Controllers\Member;

use App\Actions\Membership\ActivateMembership;
use App\Models\Membership;
use App\Models\MembershipTerm;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\MysqlTestCase;

class DashboardControllerTest extends MysqlTestCase
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

    public function test_a_member_can_view_their_own_dashboard(): void
    {
        $membership = $this->activatedMember(['full_name' => 'Nimal Perera']);

        $this->actingAs($membership->user)
            ->get(route('member.dashboard'))
            ->assertOk()
            ->assertSee('Nimal Perera')
            ->assertSee($membership->membership_number)
            ->assertSee($membership->category->name)
            ->assertSee('Active')
            ->assertSee('Yes — no payment required')
            ->assertSee($membership->terms->first()->starts_on->format('j F Y'))
            ->assertSee($membership->terms->first()->expires_on->format('j F Y'));
    }

    public function test_an_unauthenticated_visitor_is_redirected_to_login(): void
    {
        $this->get(route('member.dashboard'))->assertRedirect(route('login'));
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
            ->get(route('member.dashboard'))
            ->assertRedirect(route('login'));
    }

    public function test_a_member_cannot_see_another_members_information(): void
    {
        $mine = $this->activatedMember(['full_name' => 'Nimal Perera', 'email' => 'nimal@example.test']);
        $theirs = $this->activatedMember(['full_name' => 'Kamal Silva', 'email' => 'kamal@example.test'], 'V');

        $response = $this->actingAs($mine->user)
            ->get(route('member.dashboard', ['user' => $theirs->user_id, 'membership_id' => $theirs->id]))
            ->assertOk();

        $response->assertSee($mine->membership_number)->assertSee('Nimal Perera');
        $response->assertDontSee($theirs->membership_number)->assertDontSee('Kamal Silva');
    }

    public function test_the_current_term_prefers_the_active_term_over_an_earlier_expired_one(): void
    {
        $membership = $this->activatedMember();
        $introductory = $membership->terms->first();
        $introductory->forceFill(['status' => 'expired', 'expired_at' => now()])->save();

        $renewal = $this->insertTerm($membership, [
            'term_no' => 2,
            'term_kind' => MembershipTerm::KIND_RENEWAL,
            'status' => MembershipTerm::STATUS_ACTIVE,
            'payment_status' => 'payment_confirmed',
            'membership_plan_id' => $this->renewalPlanId($membership),
            'fee_amount' => 25,
            'fee_currency' => 'USD',
            'starts_on' => now()->toDateString(),
            'expires_on' => now()->addYear()->toDateString(),
            'activated_at' => now(),
        ]);

        $this->actingAs($membership->user)
            ->get(route('member.dashboard'))
            ->assertOk()
            ->assertSee($renewal->starts_on->format('j F Y'))
            ->assertSee($renewal->expires_on->format('j F Y'))
            ->assertDontSee('Yes — no payment required')
            ->assertDontSee($introductory->expires_on->format('j F Y'));
    }

    /**
     * A lapsed membership (M11: "membership benefits are unavailable" / "member
     * cannot log in" to the ordinary member experience) is funnelled to the
     * membership/renewal page rather than shown a normal, if "Inactive", dashboard
     * — superseding this test's earlier A5.1 expectation now that renewal exists.
     */
    public function test_a_lapsed_membership_is_redirected_to_the_membership_page_instead_of_the_dashboard(): void
    {
        $membership = $this->activatedMember();
        $introductory = $membership->terms->first();
        $introductory->forceFill(['status' => 'expired', 'expired_at' => now()])->save();

        $this->insertTerm($membership, [
            'term_no' => 2,
            'term_kind' => MembershipTerm::KIND_RENEWAL,
            'status' => MembershipTerm::STATUS_EXPIRED,
            'payment_status' => 'payment_confirmed',
            'membership_plan_id' => $this->renewalPlanId($membership),
            'fee_amount' => 25,
            'fee_currency' => 'USD',
            'starts_on' => now()->subYear()->toDateString(),
            'expires_on' => now()->subDay()->toDateString(),
            'activated_at' => now()->subYear(),
            'expired_at' => now(),
        ]);

        $this->actingAs($membership->user)
            ->get(route('member.dashboard'))
            ->assertRedirect(route('member.membership.show'))
            ->assertSessionHas('warning');
    }

    public function test_a_user_with_no_membership_gets_a_not_found_response(): void
    {
        $this->actingAs($this->admin())->get(route('member.dashboard'))->assertNotFound();
    }
}
