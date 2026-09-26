<?php

namespace Tests\Feature\Http\Controllers\Membership;

use App\Models\MembershipCategory;
use App\Models\MembershipPlan;
use App\Models\MembershipSetting;
use Tests\MysqlTestCase;

class MembershipBenefitsControllerTest extends MysqlTestCase
{
    public function test_it_lists_the_active_categories_from_the_database(): void
    {
        MembershipCategory::factory()->student()->create();
        MembershipCategory::factory()->professional()->create();
        MembershipCategory::factory()->veteran()->create();

        $response = $this->get(route('membership.benefits'))->assertOk();

        $response->assertSeeInOrder(['Student', 'Professional', 'Veteran']);
    }

    public function test_inactive_categories_are_not_shown(): void
    {
        MembershipCategory::factory()->student()->inactive()->create(['name' => 'Retired Category']);

        $response = $this->get(route('membership.benefits'))->assertOk();

        $response->assertDontSee('Retired Category');
    }

    public function test_it_shows_no_categories_gracefully_when_none_are_published(): void
    {
        $response = $this->get(route('membership.benefits'))->assertOk();

        $response->assertSee('Membership categories are not published yet.');
    }

    public function test_apply_now_links_pre_select_the_categorys_slug(): void
    {
        MembershipCategory::factory()->veteran()->create();

        $response = $this->get(route('membership.benefits'))->assertOk();

        $response->assertSee('href="'.route('membership.apply', ['category' => 'veteran']).'"', false);
    }

    /**
     * Card content is now hard-coded to match the approved reference
     * screenshot exactly (this page's earlier "always read the live plan
     * fee" behaviour was deliberately replaced) — it must not depend on
     * whether a `membership_plans` row exists at all.
     */
    public function test_the_exact_reference_prices_and_headings_show_regardless_of_configured_plans(): void
    {
        MembershipCategory::factory()->student()->create();
        MembershipCategory::factory()->professional()->create();
        MembershipCategory::factory()->veteran()->create();

        $response = $this->get(route('membership.benefits'))->assertOk();

        $response->assertSee('Aviation Student Membership');
        $response->assertSee('LKR 3,500');
        $response->assertSee('Aviation Professional Membership');
        $response->assertSee('Veteran Aviation Professional');
        $response->assertSee('LKR 3,000');
    }

    public function test_it_ignores_a_real_configured_plan_fee_in_favour_of_the_reference_price(): void
    {
        $category = MembershipCategory::factory()->student()->create();
        MembershipPlan::forceCreate([
            'membership_category_id' => $category->id,
            'name' => 'Standard renewal',
            'fee_amount' => 9999,
            'currency' => 'USD',
            'duration_months' => 12,
            'is_active' => true,
        ]);

        $response = $this->get(route('membership.benefits'))->assertOk();

        $response->assertSee('LKR 3,500');
        $response->assertDontSee('9,999');
        $response->assertDontSee('USD');
    }

    public function test_the_launching_offer_banner_is_static_and_not_read_from_settings(): void
    {
        MembershipSetting::current()->forceFill(['introductory_period_months' => 3])->save();
        MembershipCategory::factory()->student()->create();

        $response = $this->get(route('membership.benefits'))->assertOk();

        $response->assertSee('Launching Offer — First 6 Months FREE');
    }

    public function test_the_professional_card_shows_all_three_tiers(): void
    {
        MembershipCategory::factory()->professional()->create();

        $response = $this->get(route('membership.benefits'))->assertOk();

        $response->assertSeeInOrder(['Core Package', 'LKR 5,000', 'Premier Package', 'LKR 10,000', 'Inner-Circle (Prestige)', 'LKR 25,000']);
        $response->assertSee('Access job portal and apply');
        $response->assertSee('Invitations to private roundtables');
    }

    public function test_the_student_card_shows_the_eligibility_questionnaire(): void
    {
        MembershipCategory::factory()->student()->create();

        $response = $this->get(route('membership.benefits'))->assertOk();

        $response->assertSee('Check Eligibility');
        $response->assertSee('Are you currently enrolled as an aviation student?');
        $response->assertSee('Which area do you study?');
        $response->assertSee('Piloting / Flight Training');
        $response->assertSee('Do you have proof of enrollment? (Student ID / letter)');
    }

    public function test_the_student_apply_now_link_is_present_but_hidden_until_eligible(): void
    {
        $category = MembershipCategory::factory()->student()->create();

        $response = $this->get(route('membership.benefits'))->assertOk();
        $html = $response->getContent();

        $expectedHref = 'href="'.route('membership.apply', ['category' => $category->slug()]).'"';
        $this->assertStringContainsString($expectedHref, $html);

        // The link and the ineligibility message are both rendered but hidden
        // by default; a small inline script (asserted separately, since
        // PHPUnit doesn't execute JS) toggles them based on the answers.
        $this->assertMatchesRegularExpression('/data-eligibility-eligible[^>]*\bhidden\b/', $html);
        $this->assertMatchesRegularExpression('/data-eligibility-ineligible[^>]*\bhidden\b/', $html);
    }

    /**
     * All three categories now gate Apply Now behind an eligibility check —
     * Student's says "Check Eligibility" (unchanged wording); Professional's
     * and Veteran's each say "Check Your Eligibility" (distinct wording, so
     * this also proves the three summaries are never confused with each
     * other).
     */
    public function test_each_category_has_exactly_one_eligibility_summary_with_its_own_wording(): void
    {
        MembershipCategory::factory()->student()->create();
        MembershipCategory::factory()->professional()->create();
        MembershipCategory::factory()->veteran()->create();

        $html = $this->get(route('membership.benefits'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, '>Check Eligibility<'));
        $this->assertSame(2, substr_count($html, '>Check Your Eligibility<'));
    }

    public function test_the_professional_card_shows_its_eligibility_questionnaire(): void
    {
        $category = MembershipCategory::factory()->professional()->create();

        $response = $this->get(route('membership.benefits'))->assertOk();

        $response->assertSee('Is your occupation related to aviation?');
        $response->assertSee('What is your occupation?');
        $response->assertSee('Pilot / Captain');
        $response->assertSee('Aviation Regulator');
        $response->assertSee('Do you have proof of occupation? (Work ID or letter)');
        $response->assertSee('href="'.route('membership.apply', ['category' => $category->slug()]).'"', false);
    }

    public function test_the_veteran_card_shows_its_eligibility_questionnaire(): void
    {
        $category = MembershipCategory::factory()->veteran()->create();

        $response = $this->get(route('membership.benefits'))->assertOk();

        $response->assertSee('Have you worked in an aviation-related position for at least 3 years?');
        $response->assertSee('What was your profession?');
        $response->assertSee('Flight Instructor');
        $response->assertSee('Do you have proof of occupation? (Work ID or letter)');
        $response->assertSee('href="'.route('membership.apply', ['category' => $category->slug()]).'"', false);
    }

    /**
     * Professional/Veteran's occupation select ends in "Other", which must
     * reveal a free-text field — the field is present but hidden by default
     * (a small inline script, not exercised here, toggles it).
     */
    public function test_professional_and_veteran_have_a_hidden_other_text_field(): void
    {
        MembershipCategory::factory()->professional()->create();
        MembershipCategory::factory()->veteran()->create();

        $html = $this->get(route('membership.benefits'))->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, '<div data-eligibility-other hidden class="mt-2">'));
    }

    public function test_every_benefit_checkmark_is_white_not_red(): void
    {
        MembershipCategory::factory()->student()->create();
        MembershipCategory::factory()->professional()->create();
        MembershipCategory::factory()->veteran()->create();

        $html = $this->get(route('membership.benefits'))->assertOk()->getContent();

        // Every checkmark svg in the benefit lists uses text-white; none uses
        // the brand-red token that was previously used for them.
        $this->assertGreaterThan(0, substr_count($html, 'shrink-0 text-white" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"'));
        $this->assertStringNotContainsString('shrink-0 text-[#CC001F]" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"', $html);
    }
}
