<?php

namespace Tests\Feature\Http\Controllers\Membership;

use App\Models\MembershipApplication;
use App\Models\MembershipCategory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesUploads;
use Tests\MysqlTestCase;

class ApplicationStatusControllerTest extends MysqlTestCase
{
    use CreatesUploads;

    private function application(string $state = 'submitted', array $attributes = []): MembershipApplication
    {
        $category = MembershipCategory::query()->where('code', 'P')->first()
            ?? MembershipCategory::factory()->professional()->create();

        $factory = MembershipApplication::factory()->for($category, 'category');

        $factory = match ($state) {
            'more_details_required' => $factory->moreDetailsRequired(),
            'approved' => $factory->approved(),
            'rejected' => $factory->rejected(),
            default => $factory,
        };

        return $factory->create([
            'full_name' => 'Nimal Perera',
            'email' => 'nimal.status@example.test',
            ...$attributes,
        ]);
    }

    // --- access ---------------------------------------------------------------

    public function test_valid_link_shows_the_applicants_own_status_without_any_account(): void
    {
        $application = $this->application(attributes: ['submitted_at' => '2026-09-15 10:00:00']);

        $this->get($application->statusUrl())
            ->assertOk()
            ->assertSee('Track your')
            ->assertSee($application->public_id)
            ->assertSee('Nimal Perera')
            ->assertSee('Professional')
            ->assertSee('15 September 2026')
            ->assertSee('Submitted');

        $this->assertGuest();
    }

    public function test_link_works_for_a_signed_in_user_too_and_does_not_link_the_application_to_them(): void
    {
        $application = $this->application();

        $this->actingAs(User::factory()->active()->create())
            ->get($application->statusUrl())
            ->assertOk();

        $this->assertNull($application->fresh()->user_id);
    }

    public function test_unsigned_url_is_refused(): void
    {
        $application = $this->application();

        $this->get(route('applications.show', $application))->assertForbidden();
    }

    public function test_tampered_signature_or_query_is_refused(): void
    {
        $application = $this->application();
        $url = $application->statusUrl();

        $this->get($url.'0')->assertForbidden();
        $this->get(preg_replace('/signature=[^&]+/', 'signature='.str_repeat('a', 64), $url))->assertForbidden();
        $this->get(preg_replace('/expires=\d+/', 'expires='.now()->addYears(5)->timestamp, $url))->assertForbidden();
        $this->get($url.'&extra=1')->assertForbidden();
    }

    public function test_expired_link_is_refused(): void
    {
        $application = $this->application();
        $url = $application->statusUrl();

        $this->travel(config('membership.applicant_link_days'))->days();
        $this->travel(1)->minute();

        $this->get($url)->assertForbidden();
    }

    public function test_link_is_valid_until_it_expires(): void
    {
        $application = $this->application();
        $url = $application->statusUrl();

        $this->travel(config('membership.applicant_link_days') - 1)->days();

        $this->get($url)->assertOk();
    }

    public function test_a_link_for_one_application_cannot_be_used_for_another(): void
    {
        $mine = $this->application(attributes: ['email' => 'mine@example.test', 'full_name' => 'Mine Applicant']);
        $other = $this->application(attributes: ['email' => 'other@example.test', 'full_name' => 'Other Applicant']);

        $swapped = str_replace($mine->public_id, $other->public_id, $mine->statusUrl());

        $this->get($swapped)->assertForbidden()->assertDontSee('Other Applicant');
        $this->get($mine->statusUrl())->assertOk()->assertSee('Mine Applicant')->assertDontSee('Other Applicant');
    }

    public function test_unknown_reference_is_not_found(): void
    {
        $url = route('applications.show', 'aaaaaaaaaaaaaaaaaaaaaaaaaa');

        $this->get($url)->assertNotFound();
    }

    public function test_numeric_id_is_not_a_way_in(): void
    {
        $application = $this->application();

        $this->get('/applications/'.$application->id)->assertNotFound();
        $this->assertStringNotContainsString('/applications/'.$application->id.'?', $application->statusUrl());
    }

    // --- content and privacy --------------------------------------------------

    /**
     * @return array<string, array{string, string, list<string>}>
     */
    public static function statuses(): array
    {
        return [
            'submitted' => ['submitted', 'We are reviewing your application.', ['has been approved', 'has been rejected', 'additional information']],
            'more details required' => ['more_details_required', 'needs additional information', ['has been approved', 'has been rejected', 'We are reviewing']],
            'approved' => ['approved', 'Your application has been approved.', ['has been rejected', 'additional information', 'We are reviewing']],
            'rejected' => ['rejected', 'Your application has been rejected.', ['has been approved', 'additional information', 'We are reviewing']],
        ];
    }

    /**
     * @param  list<string>  $notExpected
     */
    #[DataProvider('statuses')]
    public function test_each_status_shows_its_own_message_only(string $status, string $expected, array $notExpected): void
    {
        $response = $this->get($this->application($status)->statusUrl())->assertOk()->assertSee($expected);

        foreach ($notExpected as $text) {
            $response->assertDontSee($text);
        }
    }

    public function test_approved_page_says_activation_is_separate_and_changes_nothing(): void
    {
        $application = $this->application('approved');
        $tables = ['users', 'memberships', 'membership_terms', 'payments', 'notifications', 'account_setup_tokens', 'membership_applications'];
        $before = collect($tables)->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()]);

        $this->get($application->statusUrl())
            ->assertSee('Approval and activation are separate steps')
            ->assertSee('Decision date')
            ->assertDontSee('payment', false)
            ->assertDontSee('membership number', false);

        foreach ($before as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), "{$table} must not change when viewing a status page.");
        }
        $this->assertSame('approved', $application->fresh()->status);
    }

    public function test_rejected_page_offers_a_new_application_and_mentions_no_cooldown(): void
    {
        $this->get($this->application('rejected')->statusUrl())
            ->assertSee('Apply again')
            ->assertSee(route('membership.apply'), false)
            ->assertDontSee('cooldown')
            ->assertDontSee('wait');
    }

    public function test_more_details_page_shows_a_message_but_no_response_form(): void
    {
        $this->get($this->application('more_details_required')->statusUrl())
            ->assertSee('needs additional information')
            ->assertDontSee('<form', false)
            ->assertDontSee('type="file"', false);
    }

    public function test_internal_and_sensitive_fields_are_not_exposed(): void
    {
        $application = $this->application('rejected', [
            'email' => 'private.person@example.test',
            'mobile' => '+94 71 999 0000',
            'address' => '99 Secret Street',
            'decision_note' => 'Internal reviewer note: forged letter',
        ]);

        $response = $this->get($application->statusUrl())->assertOk();

        foreach (['private.person@example.test', '+94 71 999 0000', '99 Secret Street', 'Internal reviewer note', 'forged letter'] as $secret) {
            $response->assertDontSee($secret);
        }

        $html = $response->getContent();
        foreach (['decided_by', 'proof_reviewed', 'membership_category_id', 'open_email_key', 'user_id', 'storage_path'] as $internal) {
            $this->assertStringNotContainsString($internal, $html);
        }
        $this->assertStringNotContainsString('aviation-proof', $html);
    }

    public function test_response_is_not_cached_or_leaked_through_the_referrer(): void
    {
        $this->get($this->application()->statusUrl())
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    // --- link from the A2.1 confirmation page ---------------------------------

    public function test_confirmation_page_links_to_a_working_signed_status_page(): void
    {
        Storage::fake('private');
        MembershipCategory::factory()->professional()->create();

        $confirmation = $this->post(route('membership.apply.store'), [
            'category' => 'P',
            'full_name' => 'Nimal Perera',
            'email' => 'nimal.apply@example.test',
            'mobile' => '+94 77 123 4567',
            'address' => '12 Airport Road, Colombo',
            'aviation_role' => 'First Officer',
            'aviation_organisation' => 'Example Airlines',
            'proof_documents' => [$this->pdfUpload()],
        ])->assertRedirect()->headers->get('Location');

        $page = $this->get($confirmation)->assertOk()->assertSee('View application status');

        preg_match('/href="([^"]*applications\/[^"]+signature=[^"]+)"/', $page->getContent(), $match);
        $this->assertNotEmpty($match, 'The confirmation page must contain the signed status link.');

        $this->get(html_entity_decode($match[1]))
            ->assertOk()
            ->assertSee('Nimal Perera')
            ->assertSee('We are reviewing your application.');
    }
}
