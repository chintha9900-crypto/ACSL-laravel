<?php

namespace Tests\Feature\SchoolClub;

use App\Actions\Membership\ReviewMembershipApplication;
use App\Models\Membership;
use App\Models\MembershipApplication;
use App\Models\MembershipCategory;
use App\Models\MembershipTerm;
use App\Notifications\Membership\AccountSetup;
use App\Notifications\Membership\ApprovedIntroductory;
use App\Notifications\Membership\Welcome;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\Concerns\CreatesSchoolClubs;
use Tests\Concerns\CreatesUploads;
use Tests\MysqlTestCase;

class SchoolClubMembershipTest extends MysqlTestCase
{
    use CreatesReviewableApplications, CreatesSchoolClubs, CreatesUploads;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'category' => MembershipCategory::CODE_SCHOOL_CLUB,
            'company_name' => 'Example College',
            'address' => '1 School Lane, Colombo',
            'company_email' => 'office@example-college.test',
            'company_phone' => '+94 11 234 5678',
            'company_website' => 'www.example-college.test',
            'aviation_role' => 'Example College Aviation Club',
            'full_name' => 'Rep Person',
            'email' => 'rep.person@example-college.test',
            'mobile' => '+94 77 123 4567',
            'aviation_organisation' => 'Teacher in Charge',
            'proof_documents' => [$this->pdfUpload('principal-letter.pdf')],
            'declaration' => '1',
            ...$overrides,
        ];
    }

    // --- benefits page & eligibility --------------------------------------------

    public function test_the_benefits_page_shows_the_free_school_club_card_to_the_right_of_corporate(): void
    {
        $html = $this->get(route('membership.benefits'))->assertOk()->getContent();

        $corporate = strpos($html, 'Corporate Membership</h3>');
        $schoolClub = strpos($html, 'School Club Membership</h3>');
        $this->assertNotFalse($corporate);
        $this->assertNotFalse($schoolClub);
        $this->assertGreaterThan($corporate, $schoolClub, 'School Club comes after (to the right of) Corporate.');

        $card = substr($html, $schoolClub, strpos($html, '</details>', $schoolClub) - $schoolClub);
        foreach (['Member/vendor discounts', 'Aviation news and updates', 'Access to aviation blogs/content', 'Participation in aviation events', 'Networking opportunities', 'Free', 'no joining or renewal fees', 'Check Eligibility', "Do you have an official request letter from the school principal confirming the school's aviation club?"] as $text) {
            $this->assertStringContainsString($text, $card);
        }
        $this->assertStringContainsString('name="school-club-eligibility-letter" value="yes"', $card);
        $this->assertStringContainsString('name="school-club-eligibility-letter" value="no"', $card);
        $this->assertStringContainsString('data-eligibility-ineligible hidden', $card);
        $this->assertStringContainsString('is mandatory for School Club membership', $card);
        $this->assertStringContainsString('href="'.route('membership.apply', ['category' => 'school-club']).'" data-eligibility-eligible hidden', $card);

        // Same card classes (dimensions/styling) as Corporate.
        $this->assertSame(2, substr_count($html, 'flex h-full flex-col rounded-xl border-l-4 border-[#CC001F] bg-[#4D4D4D] p-5 text-white shadow-card md:p-6">'."\n                            <div class=\"flex items-center gap-3\">"));
    }

    public function test_school_club_is_not_listed_among_individual_memberships(): void
    {
        $html = $this->get(route('membership.benefits'))->getContent();
        $individual = substr($html, strpos($html, 'Individual Memberships'), strpos($html, 'Corporate &amp; School Club Membership') - strpos($html, 'Individual Memberships'));

        $this->assertStringNotContainsString('School Club', $individual);
    }

    // --- application ------------------------------------------------------------

    public function test_the_application_form_offers_school_club_with_its_own_sections(): void
    {
        $html = $this->get(route('membership.apply', ['category' => 'school-club']))->assertOk()->getContent();

        $this->assertStringContainsString('value="SC"', $html);
        $this->assertMatchesRegularExpression('/value="SC"\s+checked/', $html);
        foreach (['2. School Details', 'School Name', 'School Email', 'School Phone', 'Aviation Club Name', 'Position', "4. Principal's Request Letter", 'Accepted: PDF only.'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertStringContainsString('id="proof_documents_school_club"', $html);
        $this->assertStringContainsString('accept=".pdf,application/pdf"', $html);
    }

    public function test_a_valid_school_club_application_is_stored_with_its_letter(): void
    {
        $this->post(route('membership.apply.store'), $this->payload())->assertSessionHasNoErrors()->assertRedirect();

        $application = MembershipApplication::query()->firstOrFail();
        $this->assertSame(MembershipCategory::CODE_SCHOOL_CLUB, $application->category->code);
        $this->assertSame('Example College', $application->company_name);
        $this->assertSame('office@example-college.test', $application->company_email);
        $this->assertSame('+94112345678', $application->company_phone);
        $this->assertSame('https://www.example-college.test', $application->company_website);
        $this->assertSame('Teacher in Charge', $application->aviation_organisation);
        $this->assertSame('rep.person@example-college.test', $application->email);
        $this->assertSame(1, $application->documents()->count());
    }

    public function test_school_club_fields_and_the_principal_letter_are_required(): void
    {
        $payload = $this->payload();
        unset($payload['company_name'], $payload['company_email'], $payload['company_phone'], $payload['aviation_organisation'], $payload['proof_documents']);

        $this->post(route('membership.apply.store'), $payload)->assertSessionHasErrors([
            'company_name' => 'The school name field is required.',
            'company_email',
            'company_phone',
            'aviation_organisation',
            'proof_documents' => "The principal's official request letter is required. Please upload it as a PDF.",
        ]);
        $this->assertSame(0, MembershipApplication::query()->count());
    }

    public function test_the_principal_letter_must_be_a_pdf_within_the_size_limit(): void
    {
        $this->post(route('membership.apply.store'), $this->payload(['proof_documents' => [$this->pngUpload('letter.png')]]))
            ->assertSessionHasErrors(['proof_documents.0' => "The principal's official request letter must be a PDF file."]);

        config(['uploads.aviation_proof.max_kb' => 1]);
        $big = $this->realUpload('letter.pdf', $this->pdfContent().str_repeat('%', 4096));
        $this->post(route('membership.apply.store'), $this->payload(['proof_documents' => [$big]]))->assertSessionHasErrors('proof_documents.0');

        $this->assertSame(0, MembershipApplication::query()->count());
    }

    public function test_invalid_school_and_representative_details_are_rejected(): void
    {
        $this->post(route('membership.apply.store'), $this->payload([
            'company_email' => 'not-an-email',
            'company_phone' => '011 234 5678',
            'email' => 'rep@',
            'mobile' => '0771234567',
        ]))->assertSessionHasErrors(['company_email', 'company_phone', 'email', 'mobile']);
    }

    public function test_submitted_school_club_applications_appear_in_the_admin_review_list_with_school_details(): void
    {
        $this->post(route('membership.apply.store'), $this->payload());
        $application = MembershipApplication::query()->firstOrFail();

        $this->actingAs($this->admin())->get(route('admin.membership-applications.index'))
            ->assertOk()->assertSee('School Club')->assertSee($application->public_id);
        $this->actingAs($this->editor())->get(route('admin.membership-applications.show', $application))
            ->assertOk()
            ->assertSee('School name')->assertSee('Example College')
            ->assertSee('Representative position')->assertSee('Teacher in Charge');
    }

    // --- admin decisions, numbering, free membership ---------------------------

    public function test_approval_sends_the_free_school_club_wording(): void
    {
        Notification::fake();
        $application = $this->application('submitted', [], 'SC');

        $this->actingAs($this->admin())->post(route('admin.membership-applications.review', $application), ['decision' => 'approved', 'proof_reviewed' => '1'])
            ->assertSessionHasNoErrors();

        Notification::assertSentOnDemand(ApprovedIntroductory::class, function (ApprovedIntroductory $n) {
            $html = $n->toMail(new \stdClass)->render();

            return str_contains($html, 'School Club membership is free — there are no joining or renewal fees')
                && ! str_contains($html, 'your first');
        });
    }

    public function test_more_details_and_decline_use_the_existing_application_workflow(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $returned = $this->application('submitted', [], 'SC');
        $declined = $this->application('submitted', ['email' => 'second@example.test'], 'SC');

        app(ReviewMembershipApplication::class)->handle($returned, $admin, 'more_details_required', null, 'Please attach the signed letter.');
        app(ReviewMembershipApplication::class)->handle($declined, $admin, 'rejected');

        $this->assertSame('more_details_required', $returned->fresh()->status);
        $this->assertSame('rejected', $declined->fresh()->status);
    }

    public function test_activation_issues_an_sc_number_a_free_12_month_term_and_a_setup_link(): void
    {
        Notification::fake();
        $application = $this->application('approved', ['email' => 'rep@example-college.test'], 'SC');

        $this->actingAs($this->admin())
            ->post(route('admin.membership-applications.activate', $application), ['confirm' => '1'])
            ->assertSessionHasNoErrors();

        $membership = Membership::query()->where('membership_application_id', $application->id)->firstOrFail();
        $year = now(config('membership.business_timezone'))->format('y');

        $this->assertMatchesRegularExpression('/^SC'.$year.'[0-9]{2}0001$/', $membership->membership_number);
        $this->assertSame(10, strlen($membership->membership_number));
        $this->assertNull($membership->parent_membership_id);
        $this->assertSame(0, $membership->student_sequence);

        $term = $membership->terms()->firstOrFail();
        $this->assertSame(MembershipTerm::KIND_INTRODUCTORY, $term->term_kind);
        $this->assertSame(MembershipTerm::PAYMENT_NOT_REQUIRED, $term->payment_status);
        $this->assertSame(12, $term->duration_months);
        $this->assertNull($term->fee_amount);
        $this->assertSame(0, DB::table('payments')->count());

        $user = $membership->user;
        $this->assertSame('pending_setup', $user->status);
        $this->assertNull(DB::table('users')->where('id', $user->id)->value('password'));
        Notification::assertSentTo($user, AccountSetup::class);
        Notification::assertSentTo($user, Welcome::class, function (Welcome $n) use ($user, $membership) {
            $html = $n->toMail($user)->render();

            return str_contains($html, $membership->membership_number)
                && str_contains($html, 'Free membership — no joining or renewal fees')
                && ! preg_match('/password\s*:/i', $html);
        });
    }

    public function test_sc_numbers_follow_the_shared_sequence_and_the_database_enforces_the_format(): void
    {
        $first = $this->schoolClub(['email' => 'one@example.test']);
        $second = $this->schoolClub(['email' => 'two@example.test']);

        $this->assertStringEndsWith('0001', $first->membership_number);
        $this->assertStringEndsWith('0002', $second->membership_number);

        $this->expectException(QueryException::class);
        DB::table('memberships')->where('id', $second->id)->update(['membership_number' => 'SCX'.substr($second->membership_number, 3)]);
    }

    public function test_the_school_clubs_card_and_dashboard_show_the_sc_number(): void
    {
        $club = $this->schoolClub();

        $this->actingAs($club->user)->get(route('member.dashboard'))->assertOk()->assertSee($club->membership_number);
        $this->actingAs($club->user)->get(route('member.membership.card'))->assertOk()->assertSee($club->membership_number)->assertSee('Active');
    }

    // --- free renewal -------------------------------------------------------------

    public function test_a_school_club_renews_free_once_within_the_window_and_never_pays(): void
    {
        $club = $this->schoolClub();
        $term = $club->terms->first();

        $this->actingAs($club->user)->post(route('member.membership.renewal.start'))
            ->assertRedirect(route('member.membership.show'))
            ->assertSessionHasErrors('renewal');
        $this->assertSame(1, $club->terms()->count());

        $this->travelTo($term->expires_on->copy()->subDays(10));
        $this->actingAs($club->user)->post(route('member.membership.renewal.start'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status');

        $renewal = $club->terms()->where('term_no', 2)->firstOrFail();
        $this->assertSame(MembershipTerm::KIND_FREE_RENEWAL, $renewal->term_kind);
        $this->assertSame(MembershipTerm::STATUS_ACTIVE, $renewal->status);
        $this->assertSame(MembershipTerm::PAYMENT_NOT_REQUIRED, $renewal->payment_status);
        $this->assertNull($renewal->membership_plan_id);
        $this->assertSame($term->expires_on->copy()->addDay()->toDateString(), $renewal->starts_on->toDateString());
        $this->assertSame(0, DB::table('payments')->count());

        // Immediately renewing again is refused (the new term is far from ending).
        $this->actingAs($club->user)->post(route('member.membership.renewal.start'))->assertSessionHasErrors('renewal');
    }

    public function test_a_lapsed_school_club_renews_from_today(): void
    {
        $club = $this->schoolClub();
        $this->travelTo($club->terms->first()->expires_on->copy()->addDays(40));

        $this->actingAs($club->user)->post(route('member.membership.renewal.start'))->assertSessionHasNoErrors();

        $this->assertSame(now(config('membership.business_timezone'))->toDateString(), $club->terms()->where('term_no', 2)->value('starts_on')->toDateString());
    }

    public function test_the_membership_page_offers_free_renewal_without_payment_wording(): void
    {
        $club = $this->schoolClub();

        $this->actingAs($club->user)->get(route('member.membership.show'))
            ->assertOk()
            ->assertSee('Renew free of charge')
            ->assertSee('there are no joining or renewal fees')
            ->assertDontSee('requires payment by bank transfer');
    }

    public function test_the_database_refuses_a_paid_style_free_renewal(): void
    {
        $club = $this->schoolClub();

        $this->expectException(QueryException::class);
        MembershipTerm::forceCreate([
            'membership_id' => $club->id, 'term_no' => 2, 'term_kind' => MembershipTerm::KIND_FREE_RENEWAL,
            'status' => MembershipTerm::STATUS_ACTIVE, 'payment_status' => MembershipTerm::PAYMENT_CONFIRMED,
            'duration_months' => 12, 'starts_on' => now()->toDateString(), 'expires_on' => now()->addYear()->toDateString(), 'activated_at' => now(),
        ]);
    }
}
