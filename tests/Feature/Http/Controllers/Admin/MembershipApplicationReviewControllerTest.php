<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Actions\Membership\ReviewMembershipApplication;
use App\Exceptions\MembershipApplicationCannotBeReviewedException;
use App\Models\MembershipApplication;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\MysqlTestCase;

class MembershipApplicationReviewControllerTest extends MysqlTestCase
{
    use CreatesReviewableApplications;

    private function review(MembershipApplication $application, array $payload): TestResponse
    {
        return $this->post(route('admin.membership-applications.review', $application), $payload);
    }

    private function historyCount(): int
    {
        return DB::table('membership_status_history')->count();
    }

    private function auditCount(): int
    {
        return DB::table('audit_logs')->where('event', 'membership_application.reviewed')->count();
    }

    // --- the three decisions --------------------------------------------------

    public function test_admin_approves_with_one_history_row_and_one_audit_row(): void
    {
        $application = $this->application();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->review($application, ['decision' => 'approved', 'proof_reviewed' => '1', 'note' => 'Licence verified'])
            ->assertRedirect(route('admin.membership-applications.show', $application))
            ->assertSessionHas('status', 'Decision recorded: Approved.');

        $fresh = $application->fresh();
        $this->assertSame('approved', $fresh->status);
        $this->assertSame($admin->id, $fresh->decided_by_user_id);
        $this->assertNotNull($fresh->decided_at);
        $this->assertNotNull($fresh->proof_reviewed_at);
        $this->assertSame($admin->id, $fresh->proof_reviewed_by_user_id);
        $this->assertSame('Licence verified', $fresh->decision_note);

        $history = DB::table('membership_status_history')->get();
        $this->assertCount(1, $history);
        $this->assertSame('application.approved', $history[0]->event);
        $this->assertSame('submitted', $history[0]->from_status);
        $this->assertSame('approved', $history[0]->to_status);
        $this->assertSame('admin', $history[0]->actor_type);
        $this->assertSame($admin->id, $history[0]->actor_user_id);
        $this->assertSame($application->id, $history[0]->membership_application_id);
        $this->assertSame('Licence verified', $history[0]->note);

        $audit = DB::table('audit_logs')->where('event', 'membership_application.reviewed')->get();
        $this->assertCount(1, $audit);
        $this->assertSame($admin->id, $audit[0]->user_id);
        $this->assertSame('user', $audit[0]->actor_type);
        $this->assertSame('membership_application', $audit[0]->subject_type);
        $this->assertSame($application->id, $audit[0]->subject_id);
        $this->assertSame(['status' => 'submitted'], json_decode($audit[0]->old_values, true));
        $this->assertSame(['status' => 'approved'], json_decode($audit[0]->new_values, true));
    }

    public function test_declining_stores_rejected_and_records_the_decision(): void
    {
        $application = $this->application();
        $admin = $this->admin();

        $this->actingAs($admin)->review($application, ['decision' => 'rejected', 'note' => 'Not aviation related'])
            ->assertSessionHas('status', 'Decision recorded: Declined.');

        $fresh = $application->fresh();
        $this->assertSame('rejected', $fresh->status);
        $this->assertSame($admin->id, $fresh->decided_by_user_id);
        $this->assertNotNull($fresh->decided_at);
        $this->assertNull($fresh->proof_reviewed_at);
        $this->assertSame('application.rejected', DB::table('membership_status_history')->value('event'));
        $this->assertSame(1, $this->historyCount());
        $this->assertSame(1, $this->auditCount());
    }

    public function test_more_details_required_records_the_request_without_a_decision(): void
    {
        $application = $this->application();

        $this->actingAs($this->admin())->review($application, ['decision' => 'more_details_required', 'note' => 'Send a clearer ID', 'request_message' => 'Please send a clearer ID photo.']);

        $fresh = $application->fresh();
        $this->assertSame('more_details_required', $fresh->status);
        $this->assertNull($fresh->decided_at);
        $this->assertNull($fresh->decided_by_user_id);
        $this->assertNull($fresh->proof_reviewed_at);
        $this->assertSame('Send a clearer ID', $fresh->decision_note);
        $this->assertSame('application.more_details_requested', DB::table('membership_status_history')->value('event'));
        $this->assertSame(1, $this->historyCount());
        $this->assertSame(1, $this->auditCount());
    }

    public function test_the_note_is_optional(): void
    {
        $application = $this->application();

        $this->actingAs($this->admin())->review($application, ['decision' => 'rejected'])->assertSessionHasNoErrors();

        $this->assertNull($application->fresh()->decision_note);
    }

    // --- validation -----------------------------------------------------------

    public function test_approval_requires_confirming_the_proof_was_reviewed(): void
    {
        $application = $this->application();

        $this->actingAs($this->admin())->review($application, ['decision' => 'approved'])
            ->assertSessionHasErrors('proof_reviewed');

        $this->assertSame('submitted', $application->fresh()->status);
        $this->assertSame(0, $this->historyCount());
        $this->assertSame(0, $this->auditCount());
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidDecisions(): array
    {
        return [
            'missing' => [null],
            'unknown' => ['pending'],
            'back to submitted' => ['submitted'],
            'display label' => ['Declined'],
            'array' => [['approved']],
        ];
    }

    #[DataProvider('invalidDecisions')]
    public function test_invalid_decisions_are_rejected_and_create_nothing(mixed $decision): void
    {
        $application = $this->application();

        $this->actingAs($this->admin())->review($application, array_filter(['decision' => $decision, 'proof_reviewed' => '1']))
            ->assertSessionHasErrors('decision');

        $this->assertSame('submitted', $application->fresh()->status);
        $this->assertSame(0, $this->historyCount());
        $this->assertSame(0, $this->auditCount());
    }

    public function test_an_over_long_note_is_rejected(): void
    {
        $application = $this->application();

        $this->actingAs($this->admin())->review($application, ['decision' => 'rejected', 'note' => str_repeat('x', 2001)])
            ->assertSessionHasErrors('note');

        $this->assertSame('submitted', $application->fresh()->status);
    }

    // --- transitions and concurrency ------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function nonSubmittedStatuses(): array
    {
        return [
            'approved' => ['approved'],
            'rejected' => ['rejected'],
            'more details required' => ['more_details_required'],
        ];
    }

    #[DataProvider('nonSubmittedStatuses')]
    public function test_an_application_that_is_not_submitted_cannot_be_reviewed_again(string $status): void
    {
        $application = $this->application($status);
        $before = $application->fresh()->only(['status', 'decided_by_user_id', 'decision_note']);

        foreach (['approved', 'rejected', 'more_details_required'] as $decision) {
            $this->actingAs($this->admin())
                ->review($application, ['decision' => $decision, 'proof_reviewed' => '1', 'note' => 'changed my mind', 'request_message' => 'Please send more.'])
                ->assertRedirect(route('admin.membership-applications.show', $application))
                ->assertSessionHasErrors('decision');
        }

        $this->assertSame($before, $application->fresh()->only(['status', 'decided_by_user_id', 'decision_note']));
        $this->assertSame(0, $this->historyCount());
        $this->assertSame(0, $this->auditCount());
    }

    public function test_a_second_admin_cannot_overwrite_the_first_admins_decision(): void
    {
        $application = $this->application();
        $first = $this->admin();
        $second = $this->admin();

        $this->actingAs($first)->review($application, ['decision' => 'approved', 'proof_reviewed' => '1']);
        $this->actingAs($second)->review($application, ['decision' => 'rejected', 'note' => 'too late'])
            ->assertSessionHasErrors('decision');

        $fresh = $application->fresh();
        $this->assertSame('approved', $fresh->status);
        $this->assertSame($first->id, $fresh->decided_by_user_id);
        $this->assertNull($fresh->decision_note);
        $this->assertSame(1, $this->historyCount());
        $this->assertSame(1, $this->auditCount());
    }

    public function test_a_stale_in_memory_application_cannot_overwrite_a_newer_decision(): void
    {
        $application = $this->application();
        $stale = MembershipApplication::query()->findOrFail($application->id);
        $first = $this->admin();
        $second = $this->admin();
        $review = app(ReviewMembershipApplication::class);

        $review->handle($application, $first, 'approved');

        $this->assertSame('submitted', $stale->status, 'The second admin still holds a stale copy.');
        $this->assertThrows(
            fn () => $review->handle($stale, $second, 'rejected', 'overwrite attempt'),
            MembershipApplicationCannotBeReviewedException::class,
        );

        $this->assertSame('approved', $application->fresh()->status);
        $this->assertSame($first->id, $application->fresh()->decided_by_user_id);
        $this->assertSame(1, $this->historyCount());
        $this->assertSame(1, $this->auditCount());
    }

    public function test_status_history_and_audit_are_atomic_with_the_status_change(): void
    {
        $application = $this->application();
        $ghost = new User;
        $ghost->id = 987654321;

        $this->assertThrows(
            fn () => app(ReviewMembershipApplication::class)->handle($application, $ghost, 'more_details_required', 'note', 'Please send more.'),
            QueryException::class,
        );

        $this->assertSame('submitted', $application->fresh()->status, 'A failed history write must roll the status change back.');
        $this->assertNull($application->fresh()->decision_note);
        $this->assertSame(0, $this->historyCount());
        $this->assertSame(0, $this->auditCount());
    }

    // --- authorization --------------------------------------------------------

    public function test_guests_and_non_admins_cannot_record_decisions(): void
    {
        $application = $this->application();

        $this->review($application, ['decision' => 'rejected'])->assertRedirect(route('login'));
        $this->actingAs($this->member())->review($application, ['decision' => 'rejected'])->assertForbidden();

        $this->assertSame('submitted', $application->fresh()->status);
        $this->assertSame(0, $this->historyCount());
        $this->assertSame(0, $this->auditCount());
    }

    public function test_an_unknown_application_is_not_found(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.membership-applications.review', 'aaaaaaaaaaaaaaaaaaaaaaaaaa'), ['decision' => 'rejected'])
            ->assertNotFound();
    }

    // --- scope guard ----------------------------------------------------------

    public function test_reviewing_creates_no_membership_term_payment_account_token_or_notification(): void
    {
        $tables = ['users', 'memberships', 'membership_terms', 'payments', 'notifications', 'account_setup_tokens', 'membership_number_sequences', 'password_reset_tokens'];
        $applications = [$this->application(), $this->application(attributes: ['email' => 'b@example.test']), $this->application(attributes: ['email' => 'c@example.test'])];
        $admin = $this->admin();
        $before = collect($tables)->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()]);

        $this->actingAs($admin);
        $this->review($applications[0], ['decision' => 'approved', 'proof_reviewed' => '1']);
        $this->review($applications[1], ['decision' => 'rejected']);
        $this->review($applications[2], ['decision' => 'more_details_required', 'request_message' => 'Please send more.']);

        foreach ($before as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), "{$table} must be unchanged by a review.");
        }
        $this->assertNull($applications[0]->fresh()->user_id);
    }
}
