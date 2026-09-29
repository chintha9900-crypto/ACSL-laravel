<?php

namespace Tests\Feature\Http\Controllers\Membership;

use App\Models\Document;
use App\Models\MembershipApplication;
use App\Models\MembershipCategory;
use App\Models\User;
use DOMDocument;
use DOMXPath;
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

    /**
     * A valid Aviation Enthusiast application payload — deliberately no
     * `proof_documents` key at all, matching what the stripped-down
     * Enthusiast form actually submits (no file input rendered).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function enthusiastPayload(array $overrides = []): array
    {
        return [
            'category' => MembershipCategory::CODE_ENTHUSIAST,
            'full_name' => 'Kasun Silva',
            'email' => 'kasun.silva@example.test',
            'mobile' => '+94 77 987 6543',
            'address' => '45 Galle Road, Colombo',
            'aviation_role' => 'Aviation Enthusiast',
            'aviation_organisation' => 'N/A',
            'declaration' => '1',
            ...$overrides,
        ];
    }

    /**
     * A valid Corporate application payload: company details, representative
     * details (reusing `full_name`/`email`/`mobile`/`aviation_role`/
     * `aviation_organisation`/`address`), and the company request letter
     * (reusing the existing `proof_documents` mechanism).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function corporatePayload(array $overrides = []): array
    {
        return [
            'category' => MembershipCategory::CODE_CORPORATE,
            'full_name' => 'Priya Fernando',
            'email' => 'priya.fernando@example-airline.test',
            'mobile' => '+94 77 555 1234',
            'address' => '1 Airport Way, Katunayake',
            'aviation_role' => 'Airline',
            'aviation_organisation' => 'Head of HR',
            'company_name' => 'Example Airline (Pvt) Ltd',
            'company_email' => 'info@example-airline.test',
            'company_phone' => '+94 11 234 5678',
            'company_website' => 'https://example-airline.test',
            'proof_documents' => [$this->pdf('company-request-letter.pdf')],
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
     * Parses a response body for precise structural queries — used to
     * distinguish which `data-category-section`/`data-category-section-
     * hide-for` a field lives inside, which a plain substring search cannot.
     */
    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();

        return new DOMXPath($dom);
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
        // Enthusiast/Corporate are always-present baseline categories
        // (docs/database/04 §3) — "no category active" must be constructed
        // explicitly now, not assumed from an empty table.
        MembershipCategory::query()->update(['is_active' => false]);

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

    // --- Aviation Enthusiast (E) -----------------------------------------------

    public function test_enthusiast_can_submit_without_proof_documents(): void
    {
        $this->enthusiast();

        $this->submit($this->enthusiastPayload())->assertRedirect();

        $this->assertSame(1, $this->tableCount('membership_applications'));
        $this->assertSame(0, $this->tableCount('documents'));
    }

    public function test_enthusiast_does_not_require_aviation_eligibility_fields(): void
    {
        $this->enthusiast();

        $this->submit($this->enthusiastPayload())
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors([
                'study_start_date', 'expected_completion_date',
                'years_experience', 'previous_employers',
                'proof_documents',
            ]);
    }

    public function test_submitted_enthusiast_data_has_no_unwanted_aviation_or_document_fields(): void
    {
        $this->enthusiast();

        $this->submit($this->enthusiastPayload())->assertRedirect();

        $application = MembershipApplication::query()->firstOrFail();
        $this->assertNull($application->study_start_date);
        $this->assertNull($application->expected_completion_date);
        $this->assertNull($application->years_experience);
        $this->assertNull($application->previous_employers);
        $this->assertNull($application->company_name);
        $this->assertNull($application->company_email);
        $this->assertNull($application->company_phone);
        $this->assertNull($application->company_website);
        $this->assertSame(0, Document::query()->where('membership_application_id', $application->id)->count());
    }

    // --- Corporate (C) ----------------------------------------------------------

    public function test_corporate_requires_company_and_representative_fields_and_the_request_letter(): void
    {
        $this->corporate();

        $this->submit(['category' => MembershipCategory::CODE_CORPORATE, 'declaration' => '1'])
            ->assertSessionHasErrors([
                'company_name', 'company_email', 'company_phone',
                'full_name', 'email', 'mobile', 'address',
                'aviation_role', 'aviation_organisation',
                'proof_documents',
            ]);

        $this->assertSame(0, $this->tableCount('membership_applications'));
    }

    public function test_corporate_company_website_is_optional(): void
    {
        $this->corporate();

        $payload = $this->corporatePayload();
        unset($payload['company_website']);

        $this->submit($payload)->assertRedirect()->assertSessionDoesntHaveErrors('company_website');

        $this->assertNull(MembershipApplication::query()->firstOrFail()->company_website);
    }

    public function test_submitted_corporate_data_is_persisted_correctly(): void
    {
        $this->corporate();

        $this->submit($this->corporatePayload())->assertRedirect();

        $application = MembershipApplication::query()->firstOrFail();
        $this->assertSame('Priya Fernando', $application->full_name);
        $this->assertSame('priya.fernando@example-airline.test', $application->email);
        $this->assertSame('+94 77 555 1234', $application->mobile);
        $this->assertSame('1 Airport Way, Katunayake', $application->address);
        $this->assertSame('Airline', $application->aviation_role);
        $this->assertSame('Head of HR', $application->aviation_organisation);
        $this->assertSame('Example Airline (Pvt) Ltd', $application->company_name);
        $this->assertSame('info@example-airline.test', $application->company_email);
        $this->assertSame('+94 11 234 5678', $application->company_phone);
        $this->assertSame('https://example-airline.test', $application->company_website);

        $document = Document::query()->where('membership_application_id', $application->id)->firstOrFail();
        Storage::disk('private')->assertExists($document->storage_path);
    }

    // --- existing categories unaffected ----------------------------------------

    public function test_existing_student_professional_veteran_validation_is_unchanged(): void
    {
        $this->professional();
        $this->submit($this->payload(['email' => 'professional@example.test']))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        MembershipCategory::factory()->student()->create();
        $this->submit($this->payload([
            'category' => MembershipCategory::CODE_STUDENT,
            'email' => 'student@example.test',
            'study_start_date' => '2026-01-01',
            'expected_completion_date' => '2026-06-01',
        ]))->assertRedirect()->assertSessionHasNoErrors();

        MembershipCategory::factory()->veteran()->create();
        $this->submit($this->payload([
            'category' => MembershipCategory::CODE_VETERAN,
            'email' => 'veteran@example.test',
            'years_experience' => 10,
            'previous_employers' => 'Example Airlines',
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(3, $this->tableCount('membership_applications'));
        $this->assertSame(3, $this->tableCount('documents'));
    }

    // --- Step 3: form rendering per category ------------------------------------

    public function test_all_five_categories_render_as_radio_choices(): void
    {
        MembershipCategory::factory()->student()->create();
        $this->professional();
        MembershipCategory::factory()->veteran()->create();
        $this->enthusiast();
        $this->corporate();

        $this->get('/membership/apply')
            ->assertOk()
            ->assertSeeHtml('value="S"')
            ->assertSeeHtml('value="P"')
            ->assertSeeHtml('value="V"')
            ->assertSeeHtml('value="E"')
            ->assertSeeHtml('value="C"')
            ->assertSee('Aviation Enthusiast')
            ->assertSee('Corporate');
    }

    public function test_enthusiast_form_hides_aviation_eligibility_and_proof_fields(): void
    {
        $this->enthusiast();
        $html = $this->get('/membership/apply?category=enthusiast')->assertOk()->getContent();
        $xpath = $this->xpath($html);

        // The proof upload lives inside a section explicitly marked to hide
        // for Enthusiast (and Corporate) — what the page's JS reads to
        // actually hide and disable it once Enthusiast is selected.
        $proofSection = $xpath->query('//*[@id="proof_documents"]/ancestor::div[@data-category-section-hide-for][1]')->item(0);
        $this->assertNotNull($proofSection, 'The proof upload must live inside a hide-for section.');
        $this->assertStringContainsString('E', $proofSection->getAttribute('data-category-section-hide-for'));

        // Never statically `required` — only conditionally, via JS — so a
        // hidden Enthusiast form can never block submission.
        $proofInput = $xpath->query('//*[@id="proof_documents"]')->item(0);
        $this->assertNotNull($proofInput);
        $this->assertFalse($proofInput->hasAttribute('required'));
    }

    public function test_corporate_form_shows_company_details_section(): void
    {
        $this->corporate();
        $html = $this->get('/membership/apply?category=corporate')->assertOk()->getContent();
        $xpath = $this->xpath($html);

        $heading = $xpath->query('//h2[contains(., "Company Details")]')->item(0);
        $this->assertNotNull($heading, 'The Corporate form must have a Company Details heading.');

        $section = $xpath->query('ancestor::div[@data-category-section="C"][1]', $heading)->item(0);
        $this->assertNotNull($section, 'Company Details must be its own Corporate-only section.');

        foreach (['company_name', 'aviation_role', 'company_email', 'company_phone', 'address', 'company_website'] as $field) {
            $this->assertSame(1, $xpath->query('.//*[@name="'.$field.'"]', $section)->length, "Company Details must contain a {$field} field.");
        }
    }

    public function test_corporate_form_shows_representative_details_section(): void
    {
        $this->corporate();
        $html = $this->get('/membership/apply?category=corporate')->assertOk()->getContent();
        $xpath = $this->xpath($html);

        $heading = $xpath->query('//h2[contains(., "Representative Details")]')->item(0);
        $this->assertNotNull($heading, 'The Corporate form must have a Representative Details heading.');

        $section = $xpath->query('ancestor::div[@data-category-section="C"][1]', $heading)->item(0);
        $this->assertNotNull($section);

        foreach (['full_name', 'email', 'aviation_organisation'] as $field) {
            $this->assertSame(1, $xpath->query('.//*[@name="'.$field.'"]', $section)->length, "Representative Details must contain a {$field} field.");
        }

        // The representative phone reuses the same country-code widget as
        // "Your details" — its combined field only gets name="mobile" via JS.
        $this->assertSame(1, $xpath->query('.//*[@data-mobile-widget]', $section)->length);
    }

    public function test_corporate_form_shows_the_company_request_letter_upload(): void
    {
        $this->corporate();
        $html = $this->get('/membership/apply?category=corporate')->assertOk()->getContent();
        $xpath = $this->xpath($html);

        $heading = $xpath->query('//h2[contains(., "Supporting Document")]')->item(0);
        $this->assertNotNull($heading, 'The Corporate form must have a Supporting Document heading.');
        $this->assertStringContainsString('Company Membership / Request Letter', $html);

        $upload = $xpath->query('//*[@id="proof_documents_corporate"]')->item(0);
        $this->assertNotNull($upload, 'The Corporate form must have its own request-letter upload input.');
        $this->assertSame('proof_documents[]', $upload->getAttribute('name'));
    }

    public function test_corporate_form_does_not_show_student_or_veteran_specific_fields(): void
    {
        $this->corporate();
        $html = $this->get('/membership/apply?category=corporate')->assertOk()->getContent();
        $xpath = $this->xpath($html);

        foreach (['study_start_date', 'expected_completion_date', 'years_experience', 'previous_employers'] as $field) {
            $input = $xpath->query('//*[@name="'.$field.'"]')->item(0);
            $this->assertNotNull($input, "{$field} must still exist for Student/Veteran progressive enhancement.");

            $hideForSection = $xpath->query('ancestor::div[@data-category-section-hide-for]', $input)->item(0);
            $this->assertNotNull($hideForSection, "{$field} must live inside a section hidden for Corporate.");
            $this->assertStringContainsString('C', $hideForSection->getAttribute('data-category-section-hide-for'));
        }
    }

    public function test_professional_form_rendering_is_unchanged(): void
    {
        $this->professional();
        $html = $this->get('/membership/apply?category=professional')->assertOk()->getContent();

        $this->assertStringContainsString('2. Your details', $html);
        $this->assertStringContainsString('3. Aviation details', $html);
        $this->assertStringContainsString('4. Aviation eligibility proof', $html);
        $this->assertStringContainsString('Course Name / Occupation / Position Held', $html);
        $this->assertStringContainsString('Training Institute / Employer / Organisation', $html);
    }

    public function test_enthusiast_and_corporate_category_preselection_works(): void
    {
        $this->enthusiast();
        $this->corporate();

        $this->assertCheckedCategories(['E'], $this->get('/membership/apply?category=enthusiast')->getContent());
        $this->assertCheckedCategories(['C'], $this->get('/membership/apply?category=corporate')->getContent());
    }
}
