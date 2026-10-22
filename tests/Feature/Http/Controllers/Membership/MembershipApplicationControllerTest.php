<?php

namespace Tests\Feature\Http\Controllers\Membership;

use App\Actions\Membership\StoreProofDocument;
use App\Actions\Membership\SubmitMembershipApplication;
use App\Http\Controllers\Membership\MembershipApplicationController;
use App\Http\Requests\Membership\StoreMembershipApplicationRequest;
use App\Models\Document;
use App\Models\MembershipApplication;
use App\Models\MembershipCategory;
use App\Models\User;
use App\Notifications\Membership\ApplicationSubmitted;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
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

    private function student(): MembershipCategory
    {
        return MembershipCategory::query()->where('code', 'S')->firstOrFail();
    }

    private function professional(): MembershipCategory
    {
        return MembershipCategory::query()->where('code', 'P')->firstOrFail();
    }

    private function veteran(): MembershipCategory
    {
        return MembershipCategory::query()->where('code', 'V')->firstOrFail();
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
        $this->student();
        $this->professional();
        $this->veteran();

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

        // This is exactly what the page's JS produces before submitting; it is
        // stored standardised to E.164.
        $this->submit($this->payload(['mobile' => '+94 77 123 4567']))->assertSessionHasNoErrors();

        $this->assertSame('+94771234567', MembershipApplication::query()->firstOrFail()->mobile);
    }

    public function test_page_is_one_form_offering_every_active_category_as_a_radio_choice(): void
    {
        $this->student();
        $this->professional();
        $this->veteran()->update(['is_active' => false]);

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
        $this->student();
        $this->professional();
        $this->veteran();

        $this->assertCheckedCategories(['V'], $this->get('/membership/apply?category=veteran')->getContent());
        $this->assertCheckedCategories(['S'], $this->get('/membership/apply?category=student')->getContent());
        $this->assertCheckedCategories([], $this->get('/membership/apply')->getContent());
        $this->assertCheckedCategories([], $this->get('/membership/apply?category=nonsense')->getContent());
    }

    public function test_inactive_category_is_not_preselected_from_the_query_string(): void
    {
        $this->student();
        $this->veteran()->update(['is_active' => false]);

        $this->assertCheckedCategories([], $this->get('/membership/apply?category=veteran')->getContent());
    }

    public function test_previous_category_choice_is_kept_when_validation_fails(): void
    {
        $this->student();
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
        $this->professional()->update(['is_active' => false]);

        $this->submit($this->payload())->assertSessionHasErrors('category');

        $this->assertSame(0, $this->tableCount('membership_applications'));
    }

    public function test_student_must_supply_ordered_study_dates(): void
    {
        $this->student();
        $student = $this->payload(['category' => 'S']);

        $this->submit($student)->assertSessionHasErrors(['study_start_date', 'expected_completion_date']);

        $this->submit([...$student, 'study_start_date' => '2026-09-01', 'expected_completion_date' => '2026-01-01'])
            ->assertSessionHasErrors('expected_completion_date');

        $this->assertSame(0, $this->tableCount('membership_applications'));
    }

    public function test_veteran_must_supply_experience_and_previous_employers(): void
    {
        $this->veteran();

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
        $this->student();
        $this->veteran();

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
     * The confirmation page shows the success message, the review/notification
     * explanation and the application reference — and nothing else about the
     * application (no status link, no applicant details, no buttons).
     */
    public function test_submission_redirects_to_a_signed_confirmation_showing_the_success_message_and_reference(): void
    {
        $this->professional();

        $response = $this->submit($this->payload());
        $application = MembershipApplication::query()->firstOrFail();

        $location = $response->headers->get('Location');
        $this->assertStringContainsString('signature=', $location);
        $this->assertStringNotContainsString('/'.$application->id.'?', $location);

        $this->get($location)
            ->assertOk()
            ->assertSee('Your application for Professional membership has been successfully submitted. The Aviation Club team will get back to you soon.')
            ->assertSee('awaiting review')
            ->assertSee('We will notify you by email of the outcome')
            ->assertSee('Application reference')
            ->assertSee($application->public_id)
            ->assertDontSee('Nimal Perera')
            ->assertDontSee('nimal.perera@example.test')
            ->assertDontSee('+94771234567')
            ->assertDontSee('12 Airport Road')
            ->assertDontSee('employment-letter.pdf')
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
        $this->assertSame('+94775551234', $application->mobile);
        $this->assertSame('1 Airport Way, Katunayake', $application->address);
        $this->assertSame('Airline', $application->aviation_role);
        $this->assertSame('Head of HR', $application->aviation_organisation);
        $this->assertSame('Example Airline (Pvt) Ltd', $application->company_name);
        $this->assertSame('info@example-airline.test', $application->company_email);
        $this->assertSame('+94112345678', $application->company_phone);
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

        $this->student();
        $this->submit($this->payload([
            'category' => MembershipCategory::CODE_STUDENT,
            'email' => 'student@example.test',
            'study_start_date' => '2026-01-01',
            'expected_completion_date' => '2026-06-01',
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $this->veteran();
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
        $this->student();
        $this->professional();
        $this->veteran();
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

    // --- submission hardening: email ------------------------------------------

    /**
     * A valid Student application payload.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function studentPayload(array $overrides = []): array
    {
        return $this->payload([
            'category' => MembershipCategory::CODE_STUDENT,
            'aviation_role' => 'Commercial Pilot Licence',
            'aviation_organisation' => 'Example Flight Academy',
            'study_start_date' => '2026-01-10',
            'expected_completion_date' => '2027-06-30',
            ...$overrides,
        ]);
    }

    public function test_valid_student_application_is_saved_and_redirects_to_the_confirmation(): void
    {
        Notification::fake();
        $category = $this->student();

        $response = $this->submit($this->studentPayload());

        $application = MembershipApplication::query()->firstOrFail();
        $response->assertSessionHasNoErrors()->assertRedirect();
        $this->assertStringContainsString('/membership/apply/submitted/'.$application->public_id, $response->headers->get('Location'));
        $this->assertSame($category->id, $application->membership_category_id);
        $this->assertSame('submitted', $application->status);
        $this->assertSame('nimal.perera@example.test', $application->email);
        $this->assertSame('+94771234567', $application->mobile);
        $this->assertSame('2026-01-10', $application->study_start_date->toDateString());
        $this->assertSame(1, $this->tableCount('documents'));
        $this->assertSame(0, $this->tableCount('users'));
        $this->assertSame(0, $this->tableCount('memberships'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedEmails(): array
    {
        return [
            'no domain dot' => ['john@gmail'],
            'single label' => ['a@b'],
            'double at' => ['john@@example.com'],
            'no at' => ['john.example.com'],
            'spaces' => ['john smith@example.com'],
        ];
    }

    #[DataProvider('malformedEmails')]
    public function test_malformed_email_is_rejected_with_a_clear_message(string $email): void
    {
        $this->professional();

        $this->submit($this->payload(['email' => $email]))
            ->assertSessionHasErrors(['email' => 'Enter a valid email address, for example name@example.com.']);

        $this->assertSame(0, $this->tableCount('membership_applications'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function accountRoles(): array
    {
        return [
            'member' => ['active'],
            'admin' => ['admin'],
            'editor' => ['editor'],
            'dev' => ['dev'],
        ];
    }

    /**
     * Activation never reuses an existing account, so an application for such
     * an email would be accepted only to fail at activation. It is refused at
     * submission instead, whatever the account's role.
     */
    #[DataProvider('accountRoles')]
    public function test_email_of_an_existing_account_without_a_membership_cannot_apply(string $state): void
    {
        Notification::fake();
        $this->professional();
        User::factory()->{$state}()->create(['email' => 'nimal.perera@example.test']);

        $this->submit($this->payload(['email' => 'NIMAL.Perera@Example.TEST']))
            ->assertSessionHasErrors(['email' => StoreMembershipApplicationRequest::EMAIL_UNAVAILABLE_MESSAGE]);

        $this->assertSame(0, $this->tableCount('membership_applications'));
        $this->assertSame(0, $this->tableCount('documents'));
        $this->assertSame(1, $this->tableCount('users'));
        $this->assertSame([], Storage::disk('private')->allFiles());
        Notification::assertNothingSent();
    }

    public function test_existing_account_check_applies_to_enthusiast_and_corporate_too(): void
    {
        $this->enthusiast();
        $this->corporate();
        User::factory()->active()->create(['email' => 'kasun.silva@example.test']);
        User::factory()->active()->create(['email' => 'priya.fernando@example-airline.test']);

        $this->submit($this->enthusiastPayload())->assertSessionHasErrors('email');
        $this->submit($this->corporatePayload())->assertSessionHasErrors('email');

        $this->assertSame(0, $this->tableCount('membership_applications'));
    }

    /**
     * The same neutral message for an existing account, an existing
     * membership and an open application: the form never reveals which.
     */
    public function test_every_blocked_email_gets_the_same_neutral_message(): void
    {
        $category = $this->professional();
        User::factory()->active()->create(['email' => 'account.only@example.test']);
        $this->createMembershipFor(User::factory()->active()->create(['email' => 'member@example.test']));
        MembershipApplication::factory()->for($category, 'category')->create(['email' => 'open.application@example.test']);

        foreach (['account.only@example.test', 'member@example.test', 'open.application@example.test'] as $email) {
            $errors = $this->submit($this->payload(['email' => $email]))->assertSessionHasErrors('email')
                ->baseResponse->getSession()->get('errors')->get('email');

            $this->assertSame([StoreMembershipApplicationRequest::EMAIL_UNAVAILABLE_MESSAGE], $errors, $email);
        }

        $message = StoreMembershipApplicationRequest::EMAIL_UNAVAILABLE_MESSAGE;
        $this->assertStringNotContainsString('An account already exists', $message);
        $this->assertStringContainsString('sign in or contact ACI', $message);
    }

    public function test_blocked_email_message_is_shown_beside_the_email_field_with_other_input_kept(): void
    {
        $this->professional();
        User::factory()->active()->create(['email' => 'nimal.perera@example.test']);

        $this->from(route('membership.apply'))
            ->followingRedirects()
            ->submit($this->payload())
            ->assertOk()
            ->assertSee('We cannot accept a new application with this email address.')
            ->assertSee('value="Nimal Perera"', false)
            ->assertSee('value="Nimal.Perera@Example.Test"', false)
            ->assertDontSee('An account already exists');
    }

    public function test_the_concurrent_duplicate_backstop_uses_the_same_neutral_message(): void
    {
        $category = $this->professional();
        $existing = MembershipApplication::factory()->for($category, 'category')->create(['email' => 'nimal.perera@example.test']);

        try {
            app(SubmitMembershipApplication::class)->handle([
                'membership_category_id' => $category->id,
                'full_name' => 'Nimal Perera',
                'email' => $existing->email,
                'mobile' => '+94771234567',
                'address' => '12 Airport Road, Colombo',
                'aviation_role' => 'First Officer',
                'aviation_organisation' => 'Example Airlines',
            ], [$this->pdf()]);
            $this->fail('A concurrent duplicate must be refused.');
        } catch (ValidationException $exception) {
            $this->assertSame([StoreMembershipApplicationRequest::EMAIL_UNAVAILABLE_MESSAGE], $exception->errors()['email']);
        }

        $this->assertSame(1, $this->tableCount('membership_applications'));
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    // --- submission hardening: phone numbers ----------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function phoneNumbersWithoutCountryCode(): array
    {
        return [
            'local with trunk zero' => ['0771234567'],
            'national without zero' => ['77 123 4567'],
            'double zero prefix' => ['0094771234567'],
        ];
    }

    #[DataProvider('phoneNumbersWithoutCountryCode')]
    public function test_phone_number_without_an_international_country_code_is_rejected(string $mobile): void
    {
        $this->professional();

        $this->submit($this->payload(['mobile' => $mobile]))->assertSessionHasErrors([
            'mobile' => 'Enter the mobile number with its international country code, starting with + (for example +94 77 123 4567).',
        ]);

        $this->assertSame(0, $this->tableCount('membership_applications'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidInternationalPhoneNumbers(): array
    {
        return [
            'too short' => ['+94 77'],
            'too long' => ['+94 7712345678901234'],
            'country code starting with zero' => ['+0 771234567'],
            'letters' => ['+94 77 CALL ME'],
            'extension text' => ['+94 77 123 4567 ext 2'],
            'two plus signs' => ['++94 77 123 4567'],
            'mostly punctuation' => ['+94 12-----34'],
        ];
    }

    #[DataProvider('invalidInternationalPhoneNumbers')]
    public function test_invalid_international_phone_number_is_rejected(string $mobile): void
    {
        $this->professional();

        $this->submit($this->payload(['mobile' => $mobile]))->assertSessionHasErrors([
            'mobile' => 'Enter a valid mobile number in international format, for example +94 77 123 4567.',
        ]);

        $this->assertSame(0, $this->tableCount('membership_applications'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function trunkZeroPhoneNumbers(): array
    {
        return [
            'zero after country code' => ['+94 077 123 4567'],
            'bracketed zero' => ['+44 (0)20 7946 0958'],
        ];
    }

    #[DataProvider('trunkZeroPhoneNumbers')]
    public function test_ambiguous_trunk_zero_after_the_country_code_is_rejected(string $mobile): void
    {
        $this->professional();

        $this->submit($this->payload(['mobile' => $mobile]))->assertSessionHasErrors([
            'mobile' => 'Remove the leading 0 after the country code from the mobile number (for example +94 77 123 4567, not +94 077 123 4567).',
        ]);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function phoneNumbersToStandardise(): array
    {
        return [
            'spaced' => ['+94 77 123 4567', '+94771234567'],
            'punctuated' => ['+94 (77) 123-4567', '+94771234567'],
            'dotted' => ['+1.202.555.0143', '+12025550143'],
            'already E.164' => ['+447946095800', '+447946095800'],
            'unseparated London' => ['+442079460958', '+442079460958'],
            'Italy keeps its leading zero' => ['+39 06 1234 5678', '+390612345678'],
        ];
    }

    #[DataProvider('phoneNumbersToStandardise')]
    public function test_valid_phone_numbers_are_stored_in_e164_format(string $mobile, string $expected): void
    {
        $this->professional();

        $this->submit($this->payload(['mobile' => $mobile]))->assertSessionHasNoErrors();

        $this->assertSame($expected, MembershipApplication::query()->firstOrFail()->mobile);
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function nonStringPhoneValues(): array
    {
        return [
            'mobile as array' => ['mobile', ['+94771234567']],
            'mobile as nested array' => ['mobile', [['+94771234567']]],
            'company phone as array' => ['company_phone', ['+94112345678']],
        ];
    }

    /**
     * A crafted non-string value must be a plain validation error — never a
     * PHP "Array to string conversion" warning turned into a 500.
     */
    #[DataProvider('nonStringPhoneValues')]
    public function test_a_non_string_phone_value_is_rejected_safely(string $field, mixed $value): void
    {
        $this->corporate();

        $this->from(route('membership.apply'))
            ->submit($this->corporatePayload([$field => $value]))
            ->assertRedirect(route('membership.apply'))
            ->assertSessionHasErrors([$field => 'The '.($field === 'mobile' ? 'mobile' : 'company phone').' field must be a string.']);

        $this->assertSame(0, $this->tableCount('membership_applications'));
    }

    // --- submission hardening: Unicode email addresses ------------------------

    /**
     * @return array<string, array{string, string}>
     */
    public static function unicodeEmails(): array
    {
        return [
            'accented local part' => ['josé.pérez@example.com', 'josé.pérez@example.com'],
            'accented local part, upper case' => ['JOSÉ@Example.com', 'josé@example.com'],
            'non-Latin local part' => ['用户@example.com', '用户@example.com'],
            'internationalised domain in punycode' => ['user@xn--exmple-cua.com', 'user@xn--exmple-cua.com'],
        ];
    }

    #[DataProvider('unicodeEmails')]
    public function test_unicode_email_addresses_are_accepted(string $email, string $stored): void
    {
        $this->professional();

        $this->submit($this->payload(['email' => $email]))->assertSessionHasNoErrors();

        $this->assertSame($stored, MembershipApplication::query()->firstOrFail()->email);
    }

    /**
     * PHP's address filter (behind Laravel's `filter`/`filter_unicode`) does
     * not accept a domain written in Unicode; such a domain must be entered in
     * its punycode form (accepted above). Rejected with the clear message.
     */
    public function test_an_internationalised_domain_written_in_unicode_is_rejected_clearly(): void
    {
        $this->professional();

        $this->submit($this->payload(['email' => 'user@exämple.com']))
            ->assertSessionHasErrors(['email' => 'Enter a valid email address, for example name@example.com.']);
    }

    public function test_corporate_company_email_accepts_a_unicode_local_part(): void
    {
        $this->corporate();

        $this->submit($this->corporatePayload(['company_email' => 'información@example-airline.test']))->assertSessionHasNoErrors();

        $this->assertSame('información@example-airline.test', MembershipApplication::query()->firstOrFail()->company_email);
    }

    // --- Corporate submission blockers ----------------------------------------

    /**
     * Regression: the Company Website field was `type="url"`, so the browser
     * refused to submit a Corporate application whose website was typed
     * without a scheme ("www.…") — the POST never reached the server.
     */
    public function test_corporate_company_website_field_does_not_block_submission_in_the_browser(): void
    {
        $this->corporate();

        $html = $this->get('/membership/apply?category=corporate')->assertOk()->getContent();
        $website = $this->xpath($html)->query('//input[@name="company_website"]')->item(0);

        $this->assertNotNull($website);
        $this->assertNotSame('url', $website->getAttribute('type'));
        $this->assertSame('url', $website->getAttribute('inputmode'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function companyWebsitesWithoutScheme(): array
    {
        return [
            'www domain' => ['www.example-airline.lk', 'https://www.example-airline.lk'],
            'bare domain' => ['example-airline.lk', 'https://example-airline.lk'],
            'with path and spaces' => ['  example-airline.lk/about  ', 'https://example-airline.lk/about'],
        ];
    }

    #[DataProvider('companyWebsitesWithoutScheme')]
    public function test_corporate_company_website_without_a_scheme_is_accepted_as_https(string $typed, string $stored): void
    {
        $this->corporate();

        $this->submit($this->corporatePayload(['company_website' => $typed]))->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame($stored, MembershipApplication::query()->firstOrFail()->company_website);
    }

    public function test_corporate_company_website_keeps_an_explicit_scheme_and_still_rejects_invalid_values(): void
    {
        $this->corporate();

        $this->submit($this->corporatePayload(['company_website' => 'not a website']))->assertSessionHasErrors('company_website');
        $this->assertSame(0, $this->tableCount('membership_applications'));

        $this->submit($this->corporatePayload(['company_website' => 'http://example-airline.lk']))->assertSessionHasNoErrors();
        $this->assertSame('http://example-airline.lk', MembershipApplication::query()->firstOrFail()->company_website);
    }

    /**
     * Regression: the upload inputs are visually hidden (1x1), so a missing
     * document made the browser block submission with an invisible bubble.
     * The widget must surface the message itself.
     */
    public function test_a_missing_upload_is_reported_visibly_by_the_upload_widget(): void
    {
        $this->corporate();

        $html = $this->get('/membership/apply?category=corporate')->assertOk()->getContent();

        $this->assertSame(2, $this->xpath($html)->query('//*[@data-proof-widget]//input[@type="file"][@data-proof-upload]')->length);
        $this->assertStringContainsString("proof.addEventListener('invalid', function () {", $html);
        $this->assertStringContainsString('fileList.textContent = proof.validationMessage', $html);
        $this->assertStringContainsString("widget.scrollIntoView({ block: 'center' });", $html);
    }

    public function test_corporate_company_phone_follows_the_same_international_rules(): void
    {
        $this->corporate();

        $this->submit($this->corporatePayload(['company_phone' => '011 234 5678']))->assertSessionHasErrors([
            'company_phone' => 'Enter the company phone number with its international country code, starting with + (for example +94 77 123 4567).',
        ]);

        $this->submit($this->corporatePayload(['company_phone' => '+94 (11) 234-5678']))->assertSessionHasNoErrors();
        $this->assertSame('+94112345678', MembershipApplication::query()->firstOrFail()->company_phone);
    }

    /**
     * Phone numbers carry no uniqueness rule (docs/database/04): a number
     * already used on another application does not block a new one.
     */
    public function test_a_phone_number_already_used_on_another_application_is_accepted(): void
    {
        $category = $this->professional();
        MembershipApplication::factory()->for($category, 'category')->create([
            'email' => 'someone.else@example.test',
            'mobile' => '+94771234567',
        ]);

        $this->submit($this->payload(['mobile' => '+94 77 123 4567']))->assertSessionHasNoErrors();

        $this->assertSame(2, MembershipApplication::query()->where('mobile', '+94771234567')->count());
    }

    public function test_the_country_code_widget_drops_a_typed_trunk_zero_except_for_italy(): void
    {
        $this->professional();

        $html = $this->get('/membership/apply')->assertOk()->getContent();

        $this->assertStringContainsString("if (code !== '+39') { number = number.replace(/^0+/, ''); }", $html);
    }

    // --- submission hardening: uploads, failures and duplicates ---------------

    public function test_a_document_that_failed_to_upload_reports_a_clear_message(): void
    {
        $this->professional();
        $failed = new UploadedFile($this->pdf()->getRealPath(), 'big.pdf', 'application/pdf', UPLOAD_ERR_INI_SIZE, true);

        $this->submit($this->payload(['proof_documents' => [$failed]]))->assertSessionHasErrors([
            'proof_documents.0' => 'A document could not be uploaded. Please check its size and try again.',
        ]);

        $this->assertSame(0, $this->tableCount('membership_applications'));
    }

    public function test_the_form_asks_for_documents_again_after_it_is_returned_with_errors(): void
    {
        $this->professional();

        $this->get('/membership/apply')->assertDontSee('Please attach your documents again.');

        $this->from(route('membership.apply'))
            ->followingRedirects()
            ->submit($this->payload(['mobile' => '0771234567']))
            ->assertOk()
            ->assertSee('Please attach your documents again.');
    }

    public function test_a_save_failure_returns_to_the_form_with_an_explanation_and_saves_nothing(): void
    {
        Notification::fake();
        Exceptions::fake();
        $this->professional();
        $this->mock(StoreProofDocument::class)
            ->shouldReceive('handle')
            ->andThrow(new RuntimeException('Disk unavailable'));

        $response = $this->from(route('membership.apply'))->submit($this->payload());

        $response->assertRedirect(route('membership.apply'))
            ->assertSessionHasErrors(['submission' => MembershipApplicationController::SAVE_FAILED_MESSAGE])
            ->assertSessionHasInput('full_name', 'Nimal Perera');
        $this->assertSame(0, $this->tableCount('membership_applications'));
        $this->assertSame(0, $this->tableCount('documents'));
        $this->assertSame(0, $this->tableCount('membership_status_history'));
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'membership_application.submitted')->count());
        Notification::assertNothingSent();
        Exceptions::assertReported(RuntimeException::class);

        $this->get(route('membership.apply'))
            ->assertOk()
            ->assertSee('Sorry, we could not save your application, so it has not been submitted.')
            ->assertDontSee('Please correct the highlighted fields')
            ->assertDontSee('submitted successfully');
    }

    public function test_a_database_failure_while_saving_is_rolled_back_and_explained(): void
    {
        Notification::fake();
        Exceptions::fake();
        $this->professional();

        // The application row and its document are written, then the status
        // history insert fails — the whole submission must roll back.
        DB::listen(function (QueryExecuted $query): void {
            if (str_starts_with($query->sql, 'insert into `membership_status_history`')) {
                throw new RuntimeException('Simulated database failure');
            }
        });

        $this->from(route('membership.apply'))
            ->submit($this->payload())
            ->assertRedirect(route('membership.apply'))
            ->assertSessionHasErrors(['submission' => MembershipApplicationController::SAVE_FAILED_MESSAGE]);

        $this->assertSame(0, $this->tableCount('membership_applications'));
        $this->assertSame(0, $this->tableCount('documents'));
        $this->assertSame([], Storage::disk('private')->allFiles());
        Notification::assertNothingSent();
    }

    public function test_an_email_failure_after_saving_still_shows_success_and_saves_once(): void
    {
        $this->professional();
        Notification::shouldReceive('route')->andThrow(new RuntimeException('SMTP down'));

        $location = $this->submit($this->payload())
            ->assertSessionHasNoErrors()
            ->assertRedirect()
            ->headers->get('Location');

        $this->assertStringContainsString('/membership/apply/submitted/', $location);
        $this->assertSame(1, $this->tableCount('membership_applications'));
        $this->assertSame(1, $this->tableCount('documents'));
    }

    public function test_an_oversized_request_returns_to_the_form_with_a_clear_message(): void
    {
        $this->professional();
        $tooLarge = (string) (100 * 1024 * 1024 * 1024);

        $this->call('POST', route('membership.apply.store'), [], [], [], ['CONTENT_LENGTH' => $tooLarge])
            ->assertRedirect(route('membership.apply', ['upload' => 'too-large']));

        $this->get(route('membership.apply', ['upload' => 'too-large']))
            ->assertOk()
            ->assertSee(MembershipApplicationController::REQUEST_TOO_LARGE_MESSAGE);
        $this->get(route('membership.apply'))->assertDontSee(MembershipApplicationController::REQUEST_TOO_LARGE_MESSAGE);
        $this->assertSame(0, $this->tableCount('membership_applications'));
    }

    public function test_an_oversized_request_elsewhere_keeps_the_default_response(): void
    {
        $this->call('POST', '/login', [], [], [], ['CONTENT_LENGTH' => (string) (100 * 1024 * 1024 * 1024)])
            ->assertStatus(413);
    }

    public function test_submitting_the_same_application_twice_saves_it_only_once(): void
    {
        Notification::fake();
        $this->professional();

        $first = $this->submit($this->payload())->assertSessionHasNoErrors()->assertRedirect();
        $this->submit($this->payload())
            ->assertSessionHasErrors(['email' => StoreMembershipApplicationRequest::EMAIL_UNAVAILABLE_MESSAGE]);

        $this->assertSame(1, $this->tableCount('membership_applications'));
        $this->assertSame(1, $this->tableCount('documents'));
        Notification::assertSentOnDemandTimes(ApplicationSubmitted::class, 1);

        // Refreshing the confirmation page re-reads it; it never re-submits.
        $location = $first->headers->get('Location');
        $this->get($location)->assertOk();
        $this->get($location)->assertOk();
        $this->assertSame(1, $this->tableCount('membership_applications'));
    }

    public function test_the_submit_button_is_guarded_against_a_double_click(): void
    {
        $this->professional();

        $html = $this->get('/membership/apply')->assertOk()->getContent();

        $this->assertSame(1, $this->xpath($html)->query('//form[@id="application-form"]//button[@type="submit"][@data-submit-button]')->length);
        $this->assertStringContainsString('if (submitting) {', $html);
        $this->assertStringContainsString('submitButton.disabled = true;', $html);
        $this->assertStringContainsString("window.addEventListener('pageshow'", $html);
    }

    public function test_corporate_representative_phone_is_repopulated_after_a_validation_error(): void
    {
        $this->corporate();

        $html = $this->from(route('membership.apply'))
            ->followingRedirects()
            ->submit($this->corporatePayload(['company_name' => '']))
            ->assertOk()
            ->getContent();

        $combined = $this->xpath($html)->query('//input[@data-mobile-combined]');
        $this->assertSame(2, $combined->length);
        foreach ($combined as $input) {
            $this->assertSame('+94 77 555 1234', $input->getAttribute('value'));
        }
    }

    // --- submission hardening: privacy of the confirmation --------------------

    public function test_a_confirmation_link_cannot_be_altered_to_show_another_application(): void
    {
        $category = $this->professional();
        $other = MembershipApplication::factory()->for($category, 'category')->create(['email' => 'other.person@example.test']);

        $location = $this->submit($this->payload())->headers->get('Location');
        $mine = MembershipApplication::query()->where('email', 'nimal.perera@example.test')->firstOrFail();

        $this->get(str_replace($mine->public_id, $other->public_id, $location))
            ->assertForbidden()
            ->assertDontSee($other->public_id);
        $this->get(route('membership.apply.submitted', $other))->assertForbidden();
    }

    // --- category-specific confirmation, email error placement, form script ----

    /**
     * @return array<string, array{string, string}>
     */
    public static function categoryConfirmations(): array
    {
        return [
            'student' => [MembershipCategory::CODE_STUDENT, 'Student'],
            'professional' => [MembershipCategory::CODE_PROFESSIONAL, 'Professional'],
            'veteran' => [MembershipCategory::CODE_VETERAN, 'Veteran Aviation Professional'],
            'enthusiast' => [MembershipCategory::CODE_ENTHUSIAST, 'Aviation Enthusiast'],
            'corporate' => [MembershipCategory::CODE_CORPORATE, 'Corporate'],
        ];
    }

    #[DataProvider('categoryConfirmations')]
    public function test_a_saved_application_redirects_to_the_confirmation_naming_its_category(string $code, string $label): void
    {
        $payload = match ($code) {
            MembershipCategory::CODE_STUDENT => $this->studentPayload(),
            MembershipCategory::CODE_PROFESSIONAL => $this->payload(),
            MembershipCategory::CODE_VETERAN => $this->payload([
                'category' => MembershipCategory::CODE_VETERAN,
                'years_experience' => 25,
                'previous_employers' => 'Example Airlines',
            ]),
            MembershipCategory::CODE_ENTHUSIAST => $this->enthusiastPayload(),
            MembershipCategory::CODE_CORPORATE => $this->corporatePayload(),
        };
        match ($code) {
            MembershipCategory::CODE_STUDENT => $this->student(),
            MembershipCategory::CODE_PROFESSIONAL => $this->professional(),
            MembershipCategory::CODE_VETERAN => $this->veteran(),
            MembershipCategory::CODE_ENTHUSIAST => $this->enthusiast(),
            MembershipCategory::CODE_CORPORATE => $this->corporate(),
        };

        $response = $this->from(route('membership.apply'))->submit($payload)->assertSessionHasNoErrors();

        $application = MembershipApplication::query()->firstOrFail();
        $location = $response->headers->get('Location');
        $this->assertStringContainsString('/membership/apply/submitted/'.$application->public_id.'?', $location);
        $this->assertStringNotContainsString(route('membership.apply').'?', $location);

        $this->get($location)
            ->assertOk()
            ->assertSee("Your application for {$label} membership has been successfully submitted. The Aviation Club team will get back to you soon.")
            ->assertSee($application->public_id);
    }

    public function test_the_confirmation_is_not_shown_when_the_save_fails(): void
    {
        Exceptions::fake();
        $this->professional();
        $this->mock(StoreProofDocument::class)->shouldReceive('handle')->andThrow(new RuntimeException('Disk unavailable'));

        $response = $this->from(route('membership.apply'))->submit($this->payload());

        $response->assertRedirect(route('membership.apply'));
        $this->assertStringNotContainsString('/membership/apply/submitted/', $response->headers->get('Location'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function emailFieldsPerLayout(): array
    {
        return [
            'individual form' => ['professional', 'email'],
            'corporate form' => ['corporate', 'email_corporate'],
        ];
    }

    #[DataProvider('emailFieldsPerLayout')]
    public function test_a_blocked_email_message_is_shown_directly_beside_the_email_textbox(string $layout, string $inputId): void
    {
        $this->professional();
        $this->corporate();
        User::factory()->active()->create(['email' => 'nimal.perera@example.test']);
        $payload = $layout === 'corporate'
            ? $this->corporatePayload(['email' => 'nimal.perera@example.test'])
            : $this->payload();

        $html = $this->from(route('membership.apply'))
            ->followingRedirects()
            ->submit($payload)
            ->assertOk()
            ->getContent();

        $beside = $this->xpath($html)->query(
            '//input[@id="'.$inputId.'"][@aria-invalid="true"]/following-sibling::p[normalize-space(.)="'.StoreMembershipApplicationRequest::EMAIL_UNAVAILABLE_MESSAGE.'"]'
        );
        $this->assertSame(1, $beside->length);
        $this->assertSame(0, $this->tableCount('membership_applications'));
    }

    /**
     * The Student/Veteran groups are nested inside the "hide for Corporate"
     * card. Enabling fields per section let that outer card re-enable and
     * re-require the hidden inner group's fields, so the browser silently
     * refused to submit. A field must now be judged by every enclosing section.
     */
    public function test_the_form_script_keeps_fields_in_hidden_nested_sections_disabled_and_optional(): void
    {
        $this->professional();

        $html = $this->get('/membership/apply')->assertOk()->getContent();

        $this->assertGreaterThan(0, $this->xpath($html)->query(
            '//*[@data-category-section-hide-for]//*[@data-category-section]//*[@data-required-when-visible]'
        )->length, 'The nested sections this guards against still exist.');
        $this->assertStringNotContainsString('setSectionVisibility', $html);
        $this->assertStringContainsString("var hiddenSectionSelector = '[data-category-section][hidden], [data-category-section-hide-for][hidden]';", $html);
        $this->assertStringContainsString('var active = !field.closest(hiddenSectionSelector);', $html);
        $this->assertStringContainsString("if (field.hasAttribute('data-required-when-visible')) { field.required = active; }", $html);
    }

    public function test_the_confirmation_does_not_require_or_expose_a_signed_in_session(): void
    {
        $this->professional();
        $location = $this->submit($this->payload())->headers->get('Location');

        $this->actingAs(User::factory()->admin()->create(['email' => 'staff@example.test']))
            ->get($location)
            ->assertOk()
            ->assertDontSee('staff@example.test')
            ->assertDontSee('nimal.perera@example.test');
    }
}
