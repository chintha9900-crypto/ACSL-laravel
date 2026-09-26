<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Actions\Membership\ReviewMembershipApplication;
use App\Models\MembershipApplication;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\Concerns\CreatesUploads;
use Tests\MysqlTestCase;

/**
 * The whole loop: admin requests more details, the applicant answers on their signed
 * link, the admin sees the answer and decides.
 */
class MoreDetailsWorkflowTest extends MysqlTestCase
{
    use CreatesReviewableApplications, CreatesUploads;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
    }

    private function answer(MembershipApplication $application, array $payload): TestResponse
    {
        auth()->logout(); // the applicant has no account
        $this->assertGuest();

        return $this->post($application->signedUrl('applications.respond'), $payload);
    }

    private function requestDetails(MembershipApplication $application, array $payload = []): void
    {
        $this->post(route('admin.membership-applications.review', $application), [
            'decision' => 'more_details_required',
            'request_message' => 'Please send a clearer photo of your licence.',
            ...$payload,
        ]);
    }

    public function test_admin_requesting_more_details_creates_the_request_status_history_and_audit(): void
    {
        $application = $this->application();
        $admin = $this->admin();

        $this->actingAs($admin)->requestDetails($application, ['note' => 'Internal: blurry scan']);

        $this->assertSame('more_details_required', $application->fresh()->status);

        $request = DB::table('membership_details_requests')->get();
        $this->assertCount(1, $request);
        $this->assertSame($application->id, $request[0]->membership_application_id);
        $this->assertSame($admin->id, $request[0]->requested_by_user_id);
        $this->assertSame('Please send a clearer photo of your licence.', $request[0]->request_message);
        $this->assertNotNull($request[0]->requested_at);
        $this->assertNull($request[0]->responded_at);
        $this->assertNull($request[0]->response_message);

        $history = DB::table('membership_status_history')->get();
        $this->assertCount(1, $history);
        $this->assertSame('application.more_details_requested', $history[0]->event);
        $this->assertSame('Internal: blurry scan', $history[0]->note);
        $this->assertSame($admin->id, $history[0]->actor_user_id);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'membership_application.reviewed')->count());
    }

    public function test_the_request_message_is_required_and_nothing_is_written_without_it(): void
    {
        $application = $this->application();

        foreach ([null, '', '   '] as $message) {
            $this->actingAs($this->admin())->requestDetails($application, ['request_message' => $message]);
        }

        $this->assertSame('submitted', $application->fresh()->status);
        $this->assertSame(0, DB::table('membership_details_requests')->count());
        $this->assertSame(0, DB::table('membership_status_history')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'membership_application.reviewed')->count());
    }

    public function test_the_request_message_is_only_needed_for_more_details_required(): void
    {
        $application = $this->application();

        $this->actingAs($this->admin())->post(route('admin.membership-applications.review', $application), ['decision' => 'rejected'])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, DB::table('membership_details_requests')->count());
    }

    public function test_a_failed_request_leaves_no_partial_records(): void
    {
        $application = $this->application();
        $ghost = new User;
        $ghost->id = 987654321;

        $this->assertThrows(
            fn () => app(ReviewMembershipApplication::class)
                ->handle($application, $ghost, 'more_details_required', null, 'Please send more.'),
            QueryException::class,
        );

        $this->assertSame('submitted', $application->fresh()->status);
        $this->assertSame(0, DB::table('membership_details_requests')->count());
        $this->assertSame(0, DB::table('membership_status_history')->count());
        $this->assertSame(0, DB::table('audit_logs')->where('event', 'membership_application.reviewed')->count());
    }

    public function test_the_database_allows_only_one_open_request_per_application(): void
    {
        $application = $this->application();
        $admin = $this->admin();
        $insert = fn () => DB::table('membership_details_requests')->insert([
            'membership_application_id' => $application->id, 'requested_by_user_id' => $admin->id,
            'request_message' => 'x', 'requested_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $insert();

        $this->assertThrows($insert, UniqueConstraintViolationException::class);
    }

    public function test_full_loop_request_response_and_re_review(): void
    {
        $application = $this->application();
        $admin = $this->admin();

        $this->actingAs($admin)->requestDetails($application);

        $this->answer($application, [
            'response_message' => 'Here is my current licence.',
            'response_documents' => [$this->pdfUpload('licence-scan.pdf')],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('submitted', $application->fresh()->status, 'The application is reviewable again.');

        $page = $this->actingAs($admin)->get(route('admin.membership-applications.show', $application))->assertOk();
        $page->assertSee('Please send a clearer photo of your licence.')
            ->assertSee('Here is my current licence.')
            ->assertSee('licence-scan.pdf')
            ->assertSee('Record decision')
            ->assertDontSee('aviation-proof');

        $responseDocument = DB::table('documents')->whereNotNull('membership_details_request_id')->first();
        $this->get(route('admin.documents.show', ['document' => $responseDocument->public_id]))->assertOk();

        $this->post(route('admin.membership-applications.review', $application), ['decision' => 'approved', 'proof_reviewed' => '1'])
            ->assertSessionHasNoErrors();

        $this->assertSame('approved', $application->fresh()->status);
        $this->assertSame(
            ['application.more_details_requested', 'application.details_responded', 'application.approved'],
            DB::table('membership_status_history')->orderBy('id')->pluck('event')->all(),
        );
        $this->assertSame(
            ['membership_application.reviewed', 'membership_application.details_responded', 'document.viewed', 'membership_application.reviewed'],
            DB::table('audit_logs')->orderBy('id')->pluck('event')->all(),
        );
    }

    public function test_after_a_response_a_second_admin_cannot_overwrite_the_re_review_decision(): void
    {
        $application = $this->application();
        $this->actingAs($this->admin())->requestDetails($application);
        $this->answer($application, ['response_message' => 'Done.']);

        $first = $this->admin();
        $second = $this->admin();
        $this->actingAs($first)->post(route('admin.membership-applications.review', $application), ['decision' => 'rejected']);
        $this->actingAs($second)->post(route('admin.membership-applications.review', $application), ['decision' => 'approved', 'proof_reviewed' => '1'])
            ->assertSessionHasErrors('decision');

        $this->assertSame('rejected', $application->fresh()->status);
        $this->assertSame($first->id, $application->fresh()->decided_by_user_id);
    }

    public function test_an_admin_can_request_more_details_again_after_a_response(): void
    {
        $application = $this->application();
        $admin = $this->admin();
        $this->actingAs($admin)->requestDetails($application);
        $this->answer($application, ['response_message' => 'First answer.']);

        $this->actingAs($admin)->requestDetails($application, ['request_message' => 'And your employer letter, please.']);

        $this->assertSame('more_details_required', $application->fresh()->status);
        $this->assertSame(2, DB::table('membership_details_requests')->count());
        $this->assertSame(1, DB::table('membership_details_requests')->whereNull('responded_at')->count());
    }
}
