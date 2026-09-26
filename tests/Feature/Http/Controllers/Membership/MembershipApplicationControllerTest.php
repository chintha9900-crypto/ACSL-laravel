<?php

namespace Tests\Feature\Http\Controllers\Membership;

use App\Models\Document;
use App\Models\MembershipApplication;
use App\Models\MembershipCategory;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesMembershipRecords;
use Tests\Concerns\CreatesUploads;
use Tests\MysqlTestCase;

class MembershipApplicationControllerTest extends MysqlTestCase
{
    use CreatesMembershipRecords, CreatesUploads;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
        Storage::fake('public');
    }

    private function professional(): MembershipCategory
    {
        return MembershipCategory::factory()->professional()->create();
    }

    private function pdf(string $name = 'employment-letter.pdf'): UploadedFile
    {
        return $this->pdfUpload($name);
    }

    /**
     * A valid Professional application payload.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'category' => MembershipCategory::CODE_PROFESSIONAL,
            'full_name' => 'Nimal Perera',
            'email' => 'Nimal.Perera@Example.Test',
            'mobile' => '+94 77 123 4567',
            'address' => '12 Airport Road, Colombo',
            'aviation_role' => 'First Officer',
            'aviation_organisation' => 'Example Airlines',
            'proof_documents' => [$this->pdf()],
            'declaration' => '1',
            ...$overrides,
        ];
    }

    private function submit(array $payload): TestResponse
    {
        return $this->post(route('membership.apply.store'), $payload);
    }

    private function tableCount(string $table): int
    {
        return DB::table($table)->count();
    }

    /**
     * Assert exactly these category radio buttons are checked in the page's <input> tags.
     *
     * @param  list<string>  $expectedCodes
     */
    private function assertCheckedCategories(array $expectedCodes, string $html): void
    {
        preg_match_all('/<input\b[^>]*name="category"[^>]*>/', $html, $inputs);

        $checked = collect($inputs[0])
            ->filter(fn (string $input) => preg_match('/\schecked\b/', $input) === 1)
            ->map(fn (string $input) => preg_match('/value="([^"]+)"/', $input, $m) ? $m[1] : null)
            ->values()
            ->all();

        $this->assertSame($expectedCodes, $checked);
    }

    // --- the page -------------------------------------------------------------

    public function test_guest_can_open_the_application_page_and_sees_the_database_categories(): void
    {
        MembershipCategory::factory()->student()->create();
        $this->professional();
        MembershipCategory::factory()->veteran()->create();

        $this->get('/membership/apply')
            ->assertOk()
            ->assertSee('Apply for membership')
            ->assertSee('Student')
            ->assertSee('Professional')
            ->assertSee('Veteran')
            ->assertDontSee('Create account');
    }

    public function test_page_explains_eligibility_review_and_that_no_account_or_activation_follows(): void
    {
        $this->professional();

        $this->get('/membership/apply')
            ->assertSee('work, study or participate in the aviation field')
            ->assertSee('aviation eligibility proof')
            ->assertSee('reviewed by ACI')
            ->assertSee('does not create a member account')
            ->assertSee('does not immediately activate membership')
            ->assertDontSee('ACSL')
            ->assertDontSee('password');
    }

    public function test_the_page_shows_a_mandatory_declaration_linking_to_the_rules_page(): void
    {
        $this->professional();

        $this->get('/membership/apply')
            ->assertSee('name="declaration"', false)
            ->assertSee('Club Rules and Policies')
            ->assertSee('href="'.route('rules').'"', false);
    }

    public function test_the_mobile_field_offers_a_country_code_selector_with_flags(): void
    {
        $this->professional();
        $html = $this->get('/membership/apply')->assertOk()->getContent();

        // The country selector and the number field carry no `name` of their
        // own — only the hidden, JS-combined field is ever named "mobile".
        $this->assertStringContainsString('data-mobile-country', $html);
        $this->assertStringContainsString('data-mobile-number', $html);
        $this->assertStringContainsString('data-mobile-combined', $html);
        $this->assertSame(1, preg_match_all('/<input\b[^>]*\bname="mobile"/', $html), 'Exactly one <input name="mobile"> should exist — the <noscript> fallback.');
        $this->assertStringContainsString('🇱🇰', $html);
        $this->assertStringContainsString('Sri Lanka', $html);
        $this->assertStringContainsString('+94', $html);
    }

    public function test_the_mobile_field_has_a_noscript_fallback_that_still_submits_as_mobile(): void
    {
        $this->professional();
        $html = $this->get('/membership/apply')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<noscript>\s*<input[^>]*name="mobile"/', $html);
    }

    public function test_a_combined_country_code_and_number_is_accepted_as_the_mobile_value(): void
    {
        $this->professional();

        // This is exactly what the page's JS produces before submitting —
        // the `mobile` field/validation/storage are otherwise untouched.
        $this->submit($this->payload(['mobile' => '+94 77 123 4567']))->assertSessionHasNoErrors();

        $this->assertSame('+94 77 123 4567', MembershipApplication::query()->firstOrFail()->mobile);
    }

    public function test_page_is_one_form_offering_every_active_category_as_a_radio_choice(): void
    {
        MembershipCategory::factory()->student()->create();
        $this->professional();
        MembershipCategory::factory()->veteran()->inactive()->create();

        $this->get('/membership/apply')
            ->assertOk()
            ->assertSeeHtml('value="S"')
            ->assertSeeHtml('value="P"')
            ->assertDontSeeHtml('value="V"')
            ->assertSee('name="full_name"', false)
            ->assertSee('name="study_start_date"', false)
            ->assertSee('name="years_experience"', false)
            ->assertSee('name="proof_documents[]"', false)
            ->assertSee('name="_token"', false)
            ->assertSee('enctype="multipart/form-data"', false)
            ->assertDontSee('?category=', false);
    }

    public function test_category_in_the_query_string_only_preselects_a_radio(): void
    {
        MembershipCategory::factory()->student()->create();
        $this->professional();
        MembershipCategory::factory()->veteran()->create();

        $this->assertCheckedCategories(['V'], $this->get('/membership/apply?category=veteran')->getContent());
        $this->assertCheckedCategories(['S'], $this->get('/membership/apply?category=student')->getContent());
        $this->assertCheckedCategories([], $this->get('/membership/apply')->getContent());
        $this->assertCheckedCategories([], $this->get('/membership/apply?category=nonsense')->getContent());
    }

    public function test_inactive_category_is_not_preselected_from_the_query_string(): void
    {
        MembershipCategory::factory()->student()->create();
        MembershipCategory::factory()->veteran()->inactive()->create();

        $this->assertCheckedCategories([], $this->get('/membership/apply?category=veteran')->getContent());
    }

    public function test_previous_category_choice_is_kept_when_validation_fails(): void
    {
        MembershipCategory::factory()->student()->create();
        $this->professional();

        $this->from('/membership/apply')->submit($this->payload(['category' => 'S', 'full_name' => '']))
            ->assertRedirect('/membership/apply')
            ->assertSessionHasErrors('full_name');

        $this->assertCheckedCategories(['S'], $this->followingRedirects()->get('/membership/apply')->getContent());
    }

    public function test_page_says_applications_are_closed_when_no_category_is_active(): void
    {
        $this->get('/membership/apply')->assertOk()->assertSee('Applications are not open');
    }

    public function test_signed_in_user_can_also_open_the_page(): void
    {
        $this->professional();

        $this->actingAs(User::factory()->active()->create())
            ->get('/membership/apply')
            ->assertOk();
    }

    // --- validation -----------------------------------------------------------

    public function test_empty_submission_reports_every_required_field(): void
    {
        $this->submit([])->assertSessionHasErrors([
            'category', 'full_name', 'email', 'mobile', 'address',
            'aviation_role', 'aviation_organisation', 'proof_documents', 'declaration',
        ]);

        $this->assertSame(0, $this->tableCount('membership_applications'));
    }

    public function test_submission_without_the_declaration_confirmed_is_rejected(): void
    {
        $this->professional();

        $this->submit($this->payload(['declaration' => null]))->assertSessionHasErrors([
            'declaration' => 'Please confirm the declaration to submit your application.',
        ]);

        $this->assertSame(0, $this->tableCount('membership_applications'));
    }

    public function test_the_declaration_is_never_persisted_on_the_application(): void
    {
        $this->professional();

        $this->submit($this->payload())->assertSessionHasNoErrors();

        $application = MembershipApplication::query()->firstOrFail();
        $this->assertArrayNotHasKey('declaration', $application->toArray());
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidCategories(): array
    {
        return [
            'unknown code' => ['X'],
            'lower-case code' => ['p'],
            'legacy tier name' => ['Basic'],
            'numeric id' => ['1'],
            'array' => [['P']],
        ];
    }

    #[DataProvider('invalidCategories')]
    public function test_unknown_category_is_rejected(mixed $category): void
    {
        $this->professional();

        $this->submit($this->payload(['category' => $category]))->assertSessionHasErrors('category');

        $this->assertSame(0, $this->tableCount('membership_applications'));
    }

    public function test_inactive_category_is_rejected(): void
    {
        MembershipCategory::factory()->professional()->inactive()->create();

        $this->submit($this->payload())->assertSessionHasErrors('category');

        $this->assertSame(0, $this->tableCount('membership_applications'));
    }

    public function test_student_must_supply_ordered_study_dates(): void
    {
        MembershipCategory::factory()->student()->create();
        $student = $this->payload(['category' => 'S']);

        $this->submit($student)->assertSessionHasErrors(['study_start_date', 'expected_completion_date']);

        $this->submit([...$student, 'study_start_date' => '2026-09-01', 'expected_completion_date' => '2026-01-01'])
            ->assertSessionHasErrors('expected_completion_date');

        $this->assertSame(0, $this->tableCount('membership_applications'));
    }

    public function test_veteran_must_supply_experience_and_previous_employers(): void
    {
        MembershipCategory::factory()->veteran()->create();

        $this->submit($this->payload(['category' => 'V']))
            ->assertSessionHasErrors(['years_experience', 'previous_employers']);
    }

    public function test_category_specific_fields_of_another_category_are_ignored(): void
    {
        $this->professional();

        $this->submit($this->payload(['study_start_date' => '2026-01-01', 'years_experience' => 12, 'previous_employers' => 'X']))
            ->assertSessionHasNoErrors();

        $application = MembershipApplication::query()->firstOrFail();
        $this->assertNull($application->study_start_date);
        $this->assertNull($application->years_experience);
        $this->assertNull($application->previous_employers);
    }

    public function test_invalid_email_and_mobile_are_rejected(): void
    {
        $this->professional();

        $this->submit($this->payload(['email' => 'not-an-email', 'mobile' => 'call me']))
            ->assertSessionHasErrors(['email', 'mobile']);
    }

    public function test_missing_aviation_proof_is_rejected_with_a_clear_message(): void
    {
        $this->professional();
        $payload = $this->payload();
        unset($payload['proof_documents']);

        $this->submit($payload)->assertSessionHasErrors([
            'proof_documents' => 'Aviation eligibility proof is required. Please upload at least one document.',
        ]);

        $this->submit($this->payload(['proof_documents' => []]))->assertSessionHasErrors('proof_documents');

        $this->assertSame(0, $this->tableCount('membership_applications'));
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function disallowedFiles(): array
    {
        return [
            'executable' => ['setup.exe', "MZ\x90\x00\x03\x00\x00\x00"],
            'php script' => ['shell.php', '<?php system($_GET["c"]); ?>'],
            'php script renamed to pdf' => ['proof.pdf', '<?php system($_GET["c"]); ?>'],
            'html document' => ['proof.html', '<html><script>alert(1)</script></html>'],
            'svg image' => ['proof.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
            'plain text' => ['proof.txt', 'I work at an airline'],
        ];
    }

    #[DataProvider('disallowedFiles')]
    public function test_file_types_other_than_pdf_jpg_png_are_rejected(string $name, string $content): void
    {
        $this->professional();

        $this->submit($this->payload(['proof_documents' => [$this->realUpload($name, $content)]]))
            ->assertSessionHasErrors(['proof_documents.0' => 'Each document must be a PDF, JPG or PNG file.']);

        $this->assertSame(0, $this->tableCount('membership_applications'));
        $this->assertSame(0, $this->tableCount('documents'));
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    public function test_a_file_over_the_size_limit_is_rejected(): void
    {
        $this->professional();
        $tooLarge = config('uploads.aviation_proof.max_kb') + 1;

        $this->submit($this->payload(['proof_documents' => [UploadedFile::fake()->create('proof.pdf', $tooLarge, 'application/pdf')]]))
            ->assertSessionHasErrors('proof_documents.0');

        $this->assertSame(0, $this->tableCount('membership_applications'));
    }

    public function test_more_files_than_the_limit_are_rejected(): void
    {
        $this->professional();
        $files = array_map(fn (int $i) => $this->pdf("proof-{$i}.pdf"), range(1, config('uploads.aviation_proof.max_files') + 1));

        $this->submit($this->payload(['proof_documents' => $files]))->assertSessionHasErrors('proof_documents');
    }

    // --- a valid submission ---------------------------------------------------

    public function test_valid_submission_creates_one_submitted_application_and_one_document(): void
    {
        $category = $this->professional();

        $response = $this->submit($this->payload());

        $application = MembershipApplication::query()->firstOrFail();
        $response->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, $this->tableCount('membership_applications'));
        $this->assertSame('submitted', $application->status);
        $this->assertSame($category->id, $application->membership_category_id);
        $this->assertSame('nimal.perera@example.test', $application->email);
        $this->assertSame('Nimal Perera', $application->full_name);
        $this->assertNotNull($application->submitted_at);
        $this->assertNull($application->user_id);
        $this->assertNull($application->decided_at);
        $this->assertNull($application->proof_reviewed_at);
        $this->assertSame(26, strlen($application->public_id));

        $this->assertSame(1, $this->tableCount('documents'));
        $this->assertSame(1, Document::query()->where('membership_application_id', $application->id)->count());
    }

    public function test_valid_submission_creates_nothing_beyond_the_application_its_document_and_history(): void
    {
        $this->professional();
        $before = collect(['users', 'memberships', 'membership_terms', 'payments', 'notifications', 'account_setup_tokens', 'password_reset_tokens'])
            ->mapWithKeys(fn (string $table) => [$table => $this->tableCount($table)]);

        $this->submit($this->payload())->assertSessionHasNoErrors();

        foreach ($before as $table => $count) {
            $this->assertSame($count, $this->tableCount($table), "{$table} must be unchanged by an application.");
        }

        $this->assertSame(1, $this->tableCount('membership_status_history'));
        $this->assertSame('application.submitted', DB::table('membership_status_history')->value('event'));
        $this->assertSame('guest', DB::table('audit_logs')->value('actor_type'));
    }

    public function test_student_and_veteran_specific_fields_are_stored(): void
    {
        MembershipCategory::factory()->student()->create();
        MembershipCategory::factory()->veteran()->create();

        $this->submit($this->payload([
            'category' => 'S', 'email' => 'student@example.test', 'aviation_role' => 'Aircraft Maintenance',
            'aviation_organisation' => 'Aviation Institute', 'study_start_date' => '2026-01-15', 'expected_completion_date' => '2027-12-15',
        ]))->assertSessionHasNoErrors();

        $this->submit($this->payload([
            'category' => 'V', 'email' => 'veteran@example.test', 'aviation_role' => 'Captain',
            'aviation_organisation' => 'Example Airlines', 'years_experience' => 25, 'previous_employers' => 'Airline A; Airline B',
        ]))->assertSessionHasNoErrors();

        $student = MembershipApplication::query()->where('email', 'student@example.test')->firstOrFail();
        $veteran = MembershipApplication::query()->where('email', 'veteran@example.test')->firstOrFail();

        $this->assertSame('2026-01-15', $student->study_start_date->toDateString());
        $this->assertSame('2027-12-15', $student->expected_completion_date->toDateString());
        $this->assertSame('S', $student->category->code);
        $this->assertSame(25, $veteran->years_experience);
        $this->assertSame('Airline A; Airline B', $veteran->previous_employers);
        $this->assertSame('V', $veteran->category->code);
    }

    public function test_signed_in_user_is_not_linked_to_the_application(): void
    {
        $this->professional();

        $this->actingAs(User::factory()->active()->create())->submit($this->payload())->assertSessionHasNoErrors();

        $this->assertNull(MembershipApplication::query()->firstOrFail()->user_id);
    }

    public function test_applicant_cannot_set_status_review_decision_or_user_fields(): void
    {
        $this->professional();
        $admin = User::factory()->active()->create();

        $this->submit($this->payload([
            'status' => 'approved',
            'user_id' => $admin->id,
            'decided_at' => now()->toDateTimeString(),
            'decided_by_user_id' => $admin->id,
            'proof_reviewed_at' => now()->toDateTimeString(),
            'proof_reviewed_by_user_id' => $admin->id,
            'decision_note' => 'pre-approved',
            'public_id' => 'aaaaaaaaaaaaaaaaaaaaaaaaaa',
            'membership_category_id' => 999,
        ]))->assertSessionHasNoErrors();

        $application = MembershipApplication::query()->firstOrFail();
        $this->assertSame('submitted', $application->status);
        $this->assertNull($application->user_id);
        $this->assertNull($application->decided_at);
        $this->assertNull($application->decided_by_user_id);
        $this->assertNull($application->proof_reviewed_at);
        $this->assertNull($application->proof_reviewed_by_user_id);
        $this->assertNull($application->decision_note);
        $this->assertNotSame('aaaaaaaaaaaaaaaaaaaaaaaaaa', $application->public_id);
        $this->assertNotSame(999, $application->membership_category_id);
    }

    // --- private, safe document storage ---------------------------------------

    public function test_proof_is_stored_on_the_private_disk_under_a_server_generated_path(): void
    {
        $this->professional();
        $upload = $this->pdf('../../etc/My Employment Letter (final).pdf');

        $this->submit($this->payload(['proof_documents' => [$upload]]))->assertSessionHasNoErrors();

        $application = MembershipApplication::query()->firstOrFail();
        $document = Document::query()->firstOrFail();

        $this->assertSame('aviation_proof', $document->kind);
        $this->assertSame('private', $document->disk);
        $this->assertMatchesRegularExpression(
            '#^aviation-proof/'.$application->public_id.'/[0-9a-z]{26}\.pdf$#',
            $document->storage_path
        );
        $this->assertStringNotContainsString('Employment', $document->storage_path);
        $this->assertStringNotContainsString('..', $document->storage_path);
        $this->assertSame('My Employment Letter (final).pdf', $document->original_filename);
        $this->assertSame('application/pdf', $document->mime_type);
        $this->assertSame(strlen($this->pdfContent()), $document->size_bytes);
        $this->assertSame(hash('sha256', $this->pdfContent()), $document->checksum_sha256);
        $this->assertNull($document->uploaded_by_user_id);
        $this->assertSame('owner_and_admin', $document->fresh()->visibility);

        Storage::disk('private')->assertExists($document->storage_path);
        $this->assertSame($this->pdfContent(), Storage::disk('private')->get($document->storage_path));
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_storage_location_is_never_serialised_or_shown_to_the_applicant(): void
    {
        $this->professional();

        $response = $this->followingRedirects()->submit($this->payload())->assertOk();

        $document = Document::query()->firstOrFail();
        $this->assertArrayNotHasKey('storage_path', $document->toArray());
        $this->assertArrayNotHasKey('disk', $document->toArray());
        $response->assertDontSee($document->storage_path)->assertDontSee('aviation-proof');
    }

    public function test_multiple_proof_files_are_stored_as_separate_documents(): void
    {
        $this->professional();
        $png = $this->realUpload('licence.png', $this->pngContent(), 'image/png');

        $this->submit($this->payload(['proof_documents' => [$this->pdf('a.pdf'), $png]]))->assertSessionHasNoErrors();

        $this->assertSame(2, $this->tableCount('documents'));
        $this->assertEqualsCanonicalizing(['application/pdf', 'image/png'], Document::query()->pluck('mime_type')->all());
        $this->assertCount(2, Storage::disk('private')->allFiles());
    }

    // --- confirmation page ----------------------------------------------------

    /**
     * The confirmation page shows only the plain success message now
     * (explicit instruction) — no reference, category note or buttons.
     */
    public function test_submission_redirects_to_a_signed_confirmation_showing_only_the_success_message(): void
    {
        $this->professional();

        $response = $this->submit($this->payload());
        $application = MembershipApplication::query()->firstOrFail();

        $location = $response->headers->get('Location');
        $this->assertStringContainsString('signature=', $location);
        $this->assertStringNotContainsString('/'.$application->id.'?', $location);

        $this->get($location)
            ->assertOk()
            ->assertSee('Your application was successfully submitted. We will get back to you soon.')
            ->assertDontSee($application->public_id)
            ->assertDontSee('does not create a member account')
            ->assertDontSee('View application status')
            ->assertDontSee('Submit another application');
    }

    public function test_confirmation_page_requires_a_valid_unexpired_signature(): void
    {
        $this->professional();
        $location = $this->submit($this->payload())->headers->get('Location');
        $application = MembershipApplication::query()->firstOrFail();

        $this->get(route('membership.apply.submitted', $application))->assertForbidden();
        $this->get(str_replace($application->public_id, strtolower(str_repeat('a', 26)), $location))->assertNotFound();

        $this->travel(25)->hours();
        $this->get($location)->assertForbidden();
    }

    // --- duplicates and blocked states ----------------------------------------

    public function test_second_open_application_for_the_same_email_is_rejected(): void
    {
        $category = $this->professional();
        MembershipApplication::factory()->for($category, 'category')->create(['email' => 'nimal.perera@example.test']);

        $this->submit($this->payload())->assertSessionHasErrors('email');

        $this->assertSame(1, $this->tableCount('membership_applications'));
        $this->assertSame(0, $this->tableCount('documents'));
    }

    public function test_email_of_an_existing_member_cannot_open_a_new_application(): void
    {
        $this->professional();
        $member = User::factory()->active()->create(['email' => 'nimal.perera@example.test']);
        $this->createMembershipFor($member);
        $applications = $this->tableCount('membership_applications');

        $this->submit($this->payload())->assertSessionHasErrors('email');

        $this->assertSame($applications, $this->tableCount('membership_applications'));
    }

    public function test_second_application_is_rejected_while_the_first_is_awaiting_more_details(): void
    {
        $category = $this->professional();
        MembershipApplication::factory()->for($category, 'category')->create([
            'email' => 'nimal.perera@example.test',
            'status' => MembershipApplication::STATUS_MORE_DETAILS_REQUIRED,
        ]);

        $this->submit($this->payload())->assertSessionHasErrors('email');

        $this->assertSame(1, $this->tableCount('membership_applications'));
    }

    public function test_rejected_applicant_may_apply_again_as_a_new_application_and_the_old_one_is_kept(): void
    {
        $category = $this->professional();
        $rejected = MembershipApplication::factory()->rejected()->for($category, 'category')->create([
            'email' => 'nimal.perera@example.test',
            'mobile' => '+94 77 123 4567',
            'decided_at' => now()->subDay(),
        ]);

        $this->submit($this->payload())->assertSessionHasNoErrors();

        $this->assertSame(2, $this->tableCount('membership_applications'));
        $this->assertSame('rejected', $rejected->fresh()->status);
        $this->assertSame(1, MembershipApplication::query()->where('status', 'submitted')->count());
    }

    public function test_application_submissions_are_throttled(): void
    {
        foreach (range(1, 6) as $attempt) {
            $this->submit([])->assertSessionHasErrors('category');
        }

        $this->submit([])->assertTooManyRequests();
    }

    // --- scope guards ---------------------------------------------------------

    public function test_no_account_creation_routes_or_password_fields_are_offered(): void
    {
        $this->professional();

        $this->get('/register')->assertNotFound();
        $this->get('/signup')->assertNotFound();
        $this->get('/membership/apply?category=professional')
            ->assertDontSee('type="password"', false)
            ->assertDontSee('name="password"', false);
    }
}
