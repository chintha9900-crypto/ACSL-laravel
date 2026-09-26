<?php

namespace Tests\Feature\Http\Controllers\Membership;

use App\Models\Document;
use App\Models\MembershipApplication;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\Concerns\CreatesUploads;
use Tests\MysqlTestCase;

class MoreDetailsResponseControllerTest extends MysqlTestCase
{
    use CreatesReviewableApplications, CreatesUploads;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
        Storage::fake('public');
    }

    /**
     * An application in `more_details_required` with one open request.
     */
    private function awaitingDetails(array $attributes = []): MembershipApplication
    {
        $application = $this->application('more_details_required', $attributes);

        DB::table('membership_details_requests')->insert([
            'membership_application_id' => $application->id,
            'requested_by_user_id' => $this->admin()->id,
            'request_message' => 'Please send a clearer photo of your licence.',
            'requested_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $application;
    }

    private function answerUrl(MembershipApplication $application): string
    {
        return $application->signedUrl('applications.respond');
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        return collect(['users', 'memberships', 'membership_terms', 'payments', 'notifications', 'account_setup_tokens'])
            ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])->all();
    }

    // --- what the applicant sees ----------------------------------------------

    public function test_applicant_sees_the_request_message_and_a_signed_response_form(): void
    {
        $application = $this->awaitingDetails();

        $page = $this->get($application->statusUrl())
            ->assertOk()
            ->assertSee('ACI needs additional information')
            ->assertSee('Please send a clearer photo of your licence.')
            ->assertSee('name="response_message"', false)
            ->assertSee('name="response_documents[]"', false)
            ->assertSee('enctype="multipart/form-data"', false);

        preg_match('/<form[^>]*action="([^"]+)"/', $page->getContent(), $match);
        $action = html_entity_decode($match[1]);
        $this->assertStringContainsString('/applications/'.$application->public_id.'/respond?', $action);
        $this->assertStringContainsString('signature=', $action);
        $this->assertStringNotContainsString('/'.$application->id.'/', $action);
        $this->assertGuest();
    }

    public function test_the_response_form_never_outlives_the_link_the_applicant_is_using(): void
    {
        $application = $this->awaitingDetails();
        $url = $application->statusUrl();
        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        $page = $this->get($url)->getContent();
        preg_match('/<form[^>]*action="([^"]+)"/', $page, $match);
        parse_str(parse_url(html_entity_decode($match[1]), PHP_URL_QUERY), $formQuery);

        $this->assertSame($query['expires'], $formQuery['expires']);
    }

    public function test_no_form_is_offered_when_nothing_is_awaiting_and_the_internal_note_is_never_shown(): void
    {
        $submitted = $this->application(attributes: ['email' => 'plain@example.test']);
        $legacy = $this->application('more_details_required', ['email' => 'legacy@example.test', 'decision_note' => 'Internal reviewer note']);

        $this->get($submitted->statusUrl())->assertDontSee('<form', false);
        $this->get($legacy->statusUrl())->assertSee('contact ACI')->assertDontSee('<form', false)->assertDontSee('Internal reviewer note');
    }

    // --- responding -----------------------------------------------------------

    public function test_applicant_answers_without_an_account_and_the_application_returns_to_submitted(): void
    {
        $application = $this->awaitingDetails();
        $before = $this->counts();

        $response = $this->post($this->answerUrl($application), ['response_message' => 'My licence is attached.'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertGuest();
        $this->assertSame('submitted', $application->fresh()->status);

        $request = DB::table('membership_details_requests')->first();
        $this->assertNotNull($request->responded_at);
        $this->assertSame('My licence is attached.', $request->response_message);
        $this->assertNull($request->open_request_key);

        $history = DB::table('membership_status_history')->get();
        $this->assertCount(1, $history);
        $this->assertSame('application.details_responded', $history[0]->event);
        $this->assertSame('more_details_required', $history[0]->from_status);
        $this->assertSame('submitted', $history[0]->to_status);
        $this->assertSame('applicant', $history[0]->actor_type);
        $this->assertNull($history[0]->actor_user_id);

        $audit = DB::table('audit_logs')->where('event', 'membership_application.details_responded')->get();
        $this->assertCount(1, $audit);
        $this->assertSame('guest', $audit[0]->actor_type);
        $this->assertNull($audit[0]->user_id);
        $this->assertSame($application->id, $audit[0]->subject_id);

        $this->assertSame($before, $this->counts(), 'A response creates no account, membership, term, payment, token or notification.');

        $this->get($response->headers->get('Location'))
            ->assertOk()
            ->assertSee('Your additional information has been received')
            ->assertSee('We are reviewing your application.')
            ->assertDontSee('<form', false);
    }

    public function test_additional_documents_are_stored_privately_and_linked_to_the_request(): void
    {
        $application = $this->awaitingDetails();

        $this->post($this->answerUrl($application), [
            'response_documents' => [$this->pdfUpload('../../etc/new licence.pdf'), $this->realUpload('id.png', $this->pngContent(), 'image/png')],
        ])->assertSessionHasNoErrors();

        $requestId = DB::table('membership_details_requests')->value('id');
        $documents = Document::query()->orderBy('id')->get();

        $this->assertCount(2, $documents);
        foreach ($documents as $document) {
            $this->assertSame('aviation_proof', $document->kind);
            $this->assertSame('private', $document->disk);
            $this->assertSame($application->id, $document->membership_application_id);
            $this->assertSame($requestId, $document->membership_details_request_id);
            $this->assertNull($document->uploaded_by_user_id);
            $this->assertMatchesRegularExpression('#^aviation-proof/'.$application->public_id.'/[0-9a-z]{26}\.(pdf|png)$#', $document->storage_path);
            $this->assertStringNotContainsString('licence', $document->storage_path);
            Storage::disk('private')->assertExists($document->storage_path);
        }

        $this->assertSame('new licence.pdf', $documents[0]->original_filename);
        $this->assertSame(['application/pdf', 'image/png'], $documents->pluck('mime_type')->all());
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->get($application->statusUrl())->assertDontSee($documents[0]->storage_path)->assertDontSee('aviation-proof');
    }

    public function test_a_message_or_a_document_is_required(): void
    {
        $application = $this->awaitingDetails();

        $this->post($this->answerUrl($application), [])
            ->assertSessionHasErrors(['response_message' => 'Write a reply or attach at least one document.']);

        $this->assertSame('more_details_required', $application->fresh()->status);
        $this->assertNull(DB::table('membership_details_requests')->value('responded_at'));
        $this->assertSame(0, DB::table('membership_status_history')->count());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function disallowedFiles(): array
    {
        return [
            'executable' => ['setup.exe', "MZ\x90\x00\x03\x00\x00\x00"],
            'php script' => ['shell.php', '<?php system($_GET["c"]); ?>'],
            'php renamed to pdf' => ['proof.pdf', '<?php system($_GET["c"]); ?>'],
            'html' => ['proof.html', '<html><script>alert(1)</script></html>'],
            'svg' => ['proof.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
        ];
    }

    #[DataProvider('disallowedFiles')]
    public function test_invalid_files_are_rejected_and_nothing_changes(string $name, string $content): void
    {
        $application = $this->awaitingDetails();

        $this->post($this->answerUrl($application), [
            'response_message' => 'Here it is.',
            'response_documents' => [$this->realUpload($name, $content)],
        ])->assertSessionHasErrors('response_documents.0');

        $this->assertSame('more_details_required', $application->fresh()->status);
        $this->assertNull(DB::table('membership_details_requests')->value('responded_at'));
        $this->assertSame(0, DB::table('documents')->count());
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    public function test_an_oversized_file_or_too_many_files_are_rejected(): void
    {
        $application = $this->awaitingDetails();
        $tooLarge = UploadedFile::fake()->create('big.pdf', config('uploads.aviation_proof.max_kb') + 1, 'application/pdf');

        $this->post($this->answerUrl($application), ['response_documents' => [$tooLarge]])->assertSessionHasErrors('response_documents.0');

        $many = array_map(fn (int $i) => $this->pdfUpload("f{$i}.pdf"), range(1, config('uploads.aviation_proof.max_files') + 1));
        $this->post($this->answerUrl($application), ['response_documents' => $many])->assertSessionHasErrors('response_documents');

        $this->assertSame(0, DB::table('documents')->count());
    }

    // --- access and replay ----------------------------------------------------

    public function test_unsigned_tampered_or_expired_response_links_are_refused(): void
    {
        $application = $this->awaitingDetails();
        $url = $this->answerUrl($application);
        $payload = ['response_message' => 'x'];

        $this->post(route('applications.respond', $application), $payload)->assertForbidden();
        $this->post($url.'0', $payload)->assertForbidden();
        $this->post(preg_replace('/expires=\d+/', 'expires='.now()->addYears(5)->timestamp, $url), $payload)->assertForbidden();

        $this->travel(config('membership.applicant_link_days') + 1)->days();
        $this->post($url, $payload)->assertForbidden();

        $this->assertSame('more_details_required', $application->fresh()->status);
        $this->assertNull(DB::table('membership_details_requests')->value('responded_at'));
    }

    public function test_an_applicant_cannot_answer_another_applicants_request(): void
    {
        $mine = $this->awaitingDetails(['email' => 'mine@example.test']);
        $other = $this->awaitingDetails(['email' => 'other@example.test']);

        $swapped = str_replace($mine->public_id, $other->public_id, $this->answerUrl($mine));
        $this->post($swapped, ['response_message' => 'I am not them.'])->assertForbidden();

        $this->post($this->answerUrl($mine), ['response_message' => 'Mine.'])->assertSessionHasNoErrors();

        $this->assertSame('submitted', $mine->fresh()->status);
        $this->assertSame('more_details_required', $other->fresh()->status);
        $this->assertSame(1, DB::table('membership_details_requests')->whereNotNull('responded_at')->count());
    }

    public function test_a_repeated_submission_is_refused_and_adds_nothing(): void
    {
        $application = $this->awaitingDetails();
        $url = $this->answerUrl($application);

        $this->post($url, ['response_message' => 'First.', 'response_documents' => [$this->pdfUpload()]])->assertSessionHasNoErrors();
        $documents = DB::table('documents')->count();

        $this->post($url, ['response_message' => 'Replay.', 'response_documents' => [$this->pdfUpload()]])
            ->assertSessionHasErrors('response_message');

        $this->assertSame('First.', DB::table('membership_details_requests')->value('response_message'));
        $this->assertSame($documents, DB::table('documents')->count());
        $this->assertSame(1, DB::table('membership_status_history')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'membership_application.details_responded')->count());
    }

    public function test_an_application_with_no_open_request_cannot_be_answered(): void
    {
        $submitted = $this->application(attributes: ['email' => 'a@example.test']);
        $legacy = $this->application('more_details_required', ['email' => 'b@example.test']);
        $approved = $this->application('approved', ['email' => 'c@example.test']);

        foreach ([$submitted, $legacy, $approved] as $application) {
            $this->post($this->answerUrl($application), ['response_message' => 'Unrequested.'])->assertSessionHasErrors('response_message');
        }

        $this->assertSame(0, DB::table('membership_status_history')->count());
        $this->assertSame('approved', $approved->fresh()->status);
        $this->assertSame('more_details_required', $legacy->fresh()->status);
    }

    public function test_a_failure_part_way_leaves_no_partial_records_and_removes_stored_files(): void
    {
        $application = $this->awaitingDetails();
        $created = 0;
        Document::creating(function () use (&$created): void {
            if (++$created === 2) {
                throw new RuntimeException('Simulated failure saving the second document.');
            }
        });

        $this->withoutExceptionHandling();
        $this->assertThrows(
            fn () => $this->post($this->answerUrl($application), [
                'response_message' => 'Two files.',
                'response_documents' => [$this->pdfUpload('a.pdf'), $this->pdfUpload('b.pdf')],
            ]),
            RuntimeException::class,
        );

        $this->assertSame('more_details_required', $application->fresh()->status);
        $this->assertNull(DB::table('membership_details_requests')->value('responded_at'));
        $this->assertNull(DB::table('membership_details_requests')->value('response_message'));
        $this->assertSame(0, DB::table('documents')->count());
        $this->assertSame(0, DB::table('membership_status_history')->count());
        $this->assertSame(0, DB::table('audit_logs')->count());
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    public function test_signing_in_as_someone_else_does_not_change_who_the_response_belongs_to(): void
    {
        $application = $this->awaitingDetails();

        $this->actingAs(User::factory()->active()->create())
            ->post($this->answerUrl($application), ['response_message' => 'From a signed-in user.'])
            ->assertSessionHasNoErrors();

        $this->assertNull($application->fresh()->user_id);
    }
}
