<?php

namespace Tests\Feature\Http\Controllers\Membership;

use App\Models\MembershipCategory;
use App\Models\MembershipPlan;
use App\Models\MembershipSetting;
use Tests\MysqlTestCase;

class MembershipBenefitsControllerTest extends MysqlTestCase
{
    /**
     * Enthusiast/Corporate are always-present baseline categories
     * (docs/database/04 §3) — reuse the migration-seeded row instead of
     * creating a second one, which would collide with `code`'s UNIQUE
     * constraint.
     */
    private function enthusiast(): MembershipCategory
    {
        return MembershipCategory::query()->where('code', MembershipCategory::CODE_ENTHUSIAST)->first()
            ?? MembershipCategory::factory()->enthusiast()->create();
    }

    private function corporate(): MembershipCategory
    {
        return MembershipCategory::query()->where('code', MembershipCategory::CODE_CORPORATE)->first()
            ?? MembershipCategory::factory()->corporate()->create();
    }

    public function test_it_lists_the_active_categories_from_the_database(): void
    {
        MembershipCategory::factory()->student()->create();
        MembershipCategory::factory()->professional()->create();
        MembershipCategory::factory()->veteran()->create();

        $response = $this->get(route('membership.benefits'))->assertOk();

        // Fixed card order (approved layout): Enthusiast, Student, Veteran,
        // Professional, Corporate — never the database's own row order.
        $response->assertSeeInOrder(['Student', 'Veteran', 'Professional']);
    }

    public function test_inactive_categories_are_not_shown(): void
    {
        MembershipCategory::factory()->student()->inactive()->create(['name' => 'Retired Category']);

        $response = $this->get(route('membership.benefits'))->assertOk();

        $response->assertDontSee('Retired Category');
    }

    public function test_it_shows_no_categories_gracefully_when_none_are_published(): void
    {
        // Enthusiast/Corporate are always-present baseline categories
        // (docs/database/04 §3) — "none published" must be constructed
        // explicitly now, not assumed from an empty table.
        MembershipCategory::query()->update(['is_active' => false]);

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

    /**
     * Reversed from an earlier explicit instruction ("static, never read
     * from settings") by a later, more specific one (Step 4: "Remove/fix
     * any hard-coded 'First 6 Months FREE' text... use the existing 3-month
     * introductory-period value dynamically wherever possible").
     */
    public function test_the_launching_offer_banner_reflects_the_live_introductory_period(): void
    {
        MembershipCategory::factory()->student()->create();
        MembershipSetting::current()->forceFill(['introductory_period_months' => 5])->save();

        $response = $this->get(route('membership.benefits'))->assertOk();

        $response->assertSee('Launching Offer — First 5 Months FREE');
        $response->assertDontSee('First 6 Months FREE');
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

    // --- Step 4: Individual Memberships / Corporate Membership sections --------

    public function test_individual_memberships_section_contains_student_professional_veteran_and_enthusiast(): void
    {
        MembershipCategory::factory()->student()->create();
        MembershipCategory::factory()->professional()->create();
        MembershipCategory::factory()->veteran()->create();
        $this->enthusiast();

        $html = $this->get(route('membership.benefits'))->assertOk()->getContent();

        $individualStart = strpos($html, 'Individual Memberships');
        $this->assertNotFalse($individualStart, 'The page must have an "Individual Memberships" heading.');

        $corporateStart = strpos($html, 'Corporate Membership');
        $individualSection = $corporateStart !== false
            ? substr($html, $individualStart, $corporateStart - $individualStart)
            : substr($html, $individualStart);

        $this->assertStringContainsString('Aviation Student Membership', $individualSection);
        $this->assertStringContainsString('Aviation Professional Membership', $individualSection);
        $this->assertStringContainsString('Veteran Aviation Professional', $individualSection);
        $this->assertStringContainsString('Aviation Enthusiast', $individualSection);
    }

    public function test_corporate_membership_section_contains_corporate(): void
    {
        $this->corporate();

        $html = $this->get(route('membership.benefits'))->assertOk()->getContent();
        $corporateStart = strpos($html, 'Corporate Membership');
        $this->assertNotFalse($corporateStart, 'The page must have a "Corporate Membership" heading.');

        $corporateSection = substr($html, $corporateStart);
        $this->assertStringContainsString('LKR 30,000', $corporateSection);
    }

    public function test_enthusiast_price_and_benefits_render(): void
    {
        $this->enthusiast();

        $response = $this->get(route('membership.benefits'))->assertOk();

        $response->assertSee('Aviation Enthusiast');
        $response->assertSee('LKR 2,000');
        $response->assertSee('Membership card');
        $response->assertSee('Member/vendor discounts');
        $response->assertSee('Aviation news and updates');
        $response->assertSee('Access to aviation blogs/content');
        $response->assertSee('Participation in aviation events');
        $response->assertSee('Networking opportunities');
    }

    public function test_corporate_price_and_benefits_render(): void
    {
        $this->corporate();

        $response = $this->get(route('membership.benefits'))->assertOk();

        $response->assertSee('LKR 30,000');
        $response->assertSee('One company membership');
        $response->assertSee('One company-admin account');
        $response->assertSee('One digital membership card');
        $response->assertSee('Networking events');
        $response->assertSee('Free advertising through Aviation Club channels');
        $response->assertSee('Corporate networking and industry connections');
        $response->assertSee('Promotional opportunities');
        $response->assertSee('Participation in selected club activities/events');
        $response->assertSee('Opportunities to connect with students and aviation professionals');
        $response->assertDontSee('10 seats');
        $response->assertDontSee('10 separate members');
    }

    public function test_enthusiast_and_corporate_cards_show_the_three_month_introductory_offer(): void
    {
        $this->enthusiast();
        $this->corporate();

        $html = $this->get(route('membership.benefits'))->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, 'Launching Offer — First 3 Months FREE'), 'Both the Enthusiast and Corporate cards must show the offer.');
    }

    public function test_enthusiast_has_no_eligibility_questionnaire(): void
    {
        $this->enthusiast();

        $html = $this->get(route('membership.benefits'))->assertOk()->getContent();
        // Scoped to before the shared eligibility <script> at the bottom of
        // the page, which always mentions the `[data-eligibility]` selector
        // literally regardless of which categories exist.
        $cardsHtml = substr($html, 0, strpos($html, '<script>'));

        $this->assertStringNotContainsString('data-eligibility', $cardsHtml);
        $this->assertStringNotContainsString('Check Eligibility', $cardsHtml);
        $this->assertStringNotContainsString('Check Your Eligibility', $cardsHtml);
    }

    public function test_corporate_has_no_eligibility_questionnaire(): void
    {
        $this->corporate();

        $html = $this->get(route('membership.benefits'))->assertOk()->getContent();
        $cardsHtml = substr($html, 0, strpos($html, '<script>'));

        $this->assertStringNotContainsString('data-eligibility', $cardsHtml);
    }

    public function test_enthusiast_apply_now_preselects_category(): void
    {
        $this->enthusiast();

        $response = $this->get(route('membership.benefits'))->assertOk();

        $response->assertSee('href="'.route('membership.apply', ['category' => 'enthusiast']).'"', false);
    }

    public function test_corporate_apply_now_preselects_category(): void
    {
        $this->corporate();

        $response = $this->get(route('membership.benefits'))->assertOk();

        $response->assertSee('href="'.route('membership.apply', ['category' => 'corporate']).'"', false);
    }

    public function test_existing_student_professional_veteran_content_remains_present(): void
    {
        MembershipCategory::factory()->student()->create();
        MembershipCategory::factory()->professional()->create();
        MembershipCategory::factory()->veteran()->create();

        $response = $this->get(route('membership.benefits'))->assertOk();

        $response->assertSee('Aviation Student Membership');
        $response->assertSee('LKR 3,500');
        $response->assertSee('Aviation Professional Membership');
        $response->assertSeeInOrder(['Core Package', 'LKR 5,000', 'Premier Package', 'LKR 10,000', 'Inner-Circle (Prestige)', 'LKR 25,000']);
        $response->assertSee('Veteran Aviation Professional');
        $response->assertSee('LKR 3,000');
        $response->assertSee('Check Eligibility');
        $response->assertSee('Check Your Eligibility');
    }
}
