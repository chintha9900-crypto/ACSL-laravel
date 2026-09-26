<?php

namespace Tests\Feature\Actions\Membership;

use App\Actions\Auth\IssueAccountSetupToken;
use App\Actions\Membership\ActivatedMembership;
use App\Actions\Membership\ActivateMembership;
use App\Exceptions\ActivationEmailAlreadyInUseException;
use App\Exceptions\MembershipCannotBeActivatedException;
use App\Models\Membership;
use App\Models\MembershipApplication;
use App\Models\MembershipSetting;
use App\Models\MembershipTerm;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\MysqlTestCase;

class ActivateMembershipTest extends MysqlTestCase
{
    use CreatesReviewableApplications;

    private User $defaultAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        // Created up front so before/after table counts are not skewed by the acting admin.
        $this->defaultAdmin = $this->admin();
    }

    private function at(string $utc): void
    {
        $this->travelTo(Carbon::parse($utc, 'UTC'));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function approved(array $attributes = [], string $category = 'P'): MembershipApplication
    {
        return $this->application('approved', $attributes, $category);
    }

    private function activate(MembershipApplication $application, ?User $admin = null): Membership
    {
        return $this->activateWithToken($application, $admin)->membership;
    }

    private function activateWithToken(MembershipApplication $application, ?User $admin = null): ActivatedMembership
    {
        return app(ActivateMembership::class)->handle($application, $admin ?? $this->defaultAdmin);
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        return collect([
            'users', 'memberships', 'membership_terms', 'membership_number_sequences', 'account_setup_tokens',
            'payments', 'membership_plans', 'payment_bank_accounts', 'notifications',
            'membership_status_history', 'audit_logs',
        ])->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])->all();
    }

    // --- what activation creates ----------------------------------------------

    public function test_an_approved_application_is_activated_with_a_member_a_term_and_an_account(): void
    {
        $this->at('2026-09-21 06:00:00');
        $application = $this->approved(['full_name' => 'Nimal Perera', 'email' => 'nimal@example.test']);

        $membership = $this->activate($application);

        $this->assertSame($application->id, $membership->membership_application_id);
        $this->assertSame($application->membership_category_id, $membership->membership_category_id);
        $this->assertSame(2026, $membership->number_year);
        $this->assertSame(1, $membership->number_sequence);
        $this->assertSame('2026-09-21', $membership->activated_on->toDateString());
        $this->assertSame('2026-09-21 06:00:00', $membership->activated_at->utc()->toDateTimeString());
        $this->assertNull(DB::table('memberships')->value('verification_token'));
        $this->assertSame('approved', $application->fresh()->status, 'Activation does not change the application status.');
        $this->assertSame(1, DB::table('memberships')->count());
        $this->assertSame(1, DB::table('membership_terms')->count());
    }

    public function test_the_business_timezone_decides_the_activation_date_and_the_number_year(): void
    {
        $this->assertSame('Asia/Colombo', config('membership.business_timezone'));

        // 20:00 UTC on 31 Dec is 01:30 on 1 Jan in Colombo.
        $this->at('2026-12-31 20:00:00');
        $membership = $this->activate($this->approved());

        $this->assertSame(2027, $membership->number_year);
        $this->assertSame('2027-01-01', $membership->activated_on->toDateString());
        $this->assertSame('2026-12-31 20:00:00', $membership->activated_at->utc()->toDateTimeString(), 'The instant stays UTC.');
        $this->assertStringStartsWith('P27', $membership->membership_number);
        $this->assertSame('2027-01-01', MembershipTerm::query()->firstOrFail()->starts_on->toDateString());
    }

    public function test_the_introductory_term_is_free_and_uses_the_configured_duration(): void
    {
        $this->at('2026-10-15 06:00:00');
        $membership = $this->activate($this->approved());

        $term = DB::table('membership_terms')->first();
        $this->assertSame($membership->id, $term->membership_id);
        $this->assertSame(1, $term->term_no);
        $this->assertSame('introductory', $term->term_kind);
        $this->assertSame('active', $term->status);
        $this->assertSame('payment_not_required', $term->payment_status);
        $this->assertSame(6, $term->duration_months, 'The default introductory period is 6 months.');
        $this->assertSame('2026-10-15', $term->starts_on);
        $this->assertSame('2027-04-14', $term->expires_on);
        $this->assertNull($term->membership_plan_id);
        $this->assertNull($term->fee_amount);
        $this->assertNull($term->fee_currency);
        $this->assertNotNull($term->activated_at);

        MembershipSetting::current();
        DB::table('membership_settings')->where('id', 1)->update(['introductory_period_months' => 3]);
        $second = $this->activate($this->approved());
        $this->assertSame(3, DB::table('membership_terms')->where('membership_id', $second->id)->value('duration_months'));
        $this->assertSame('2027-01-14', DB::table('membership_terms')->where('membership_id', $second->id)->value('expires_on'));
        $this->assertSame(6, $term->duration_months, 'An existing term keeps its own snapshot.');
    }

    /**
     * @return array<string, array{string, int, string}>
     */
    public static function expiryCases(): array
    {
        return [
            'documented example' => ['2026-10-15 06:00:00', 6, '2027-04-14'],
            'month end does not overflow' => ['2026-08-31 06:00:00', 6, '2027-02-27'],
            'leap year month end' => ['2027-08-31 06:00:00', 6, '2028-02-28'],
            'one month from the 31st' => ['2026-01-31 06:00:00', 1, '2026-02-27'],
            'year end' => ['2026-12-31 06:00:00', 6, '2027-06-29'],
        ];
    }

    #[DataProvider('expiryCases')]
    public function test_expiry_is_start_plus_calendar_months_minus_one_day_without_overflow(string $utc, int $months, string $expected): void
    {
        $this->at($utc);
        MembershipSetting::current();
        DB::table('membership_settings')->where('id', 1)->update(['introductory_period_months' => $months]);

        $this->activate($this->approved());

        $this->assertSame($expected, DB::table('membership_terms')->value('expires_on'));
    }

    public function test_activation_creates_no_payment_plan_bank_account_or_notification(): void
    {
        $this->activate($this->approved());

        $this->assertSame(0, DB::table('payments')->count());
        $this->assertSame(0, DB::table('membership_plans')->count());
        $this->assertSame(0, DB::table('payment_bank_accounts')->count());
        $this->assertSame(0, DB::table('notifications')->count());
    }

    // --- eligibility ----------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function notApproved(): array
    {
        return [
            'submitted' => ['submitted'],
            'more details required' => ['more_details_required'],
            'rejected' => ['rejected'],
        ];
    }

    #[DataProvider('notApproved')]
    public function test_an_application_that_is_not_approved_cannot_be_activated(string $state): void
    {
        $application = $this->application($state);
        $before = $this->counts();

        $this->assertThrows(fn () => $this->activate($application), MembershipCannotBeActivatedException::class);

        $this->assertSame($before, $this->counts());
    }

    // --- the membership number ------------------------------------------------

    public function test_the_number_has_the_documented_format_for_every_category(): void
    {
        $this->at('2026-09-21 06:00:00');

        foreach (['S', 'P', 'V'] as $code) {
            $membership = $this->activate($this->approved(category: $code));

            $this->assertMatchesRegularExpression('/^'.$code.'26[0-9]{2}0001$/', $membership->membership_number);
            $this->assertSame($code, substr($membership->membership_number, 0, 1));
            $this->assertSame('26', substr($membership->membership_number, 1, 2));
            $this->assertSame('0001', substr($membership->membership_number, 5, 4));
        }
    }

    public function test_numbers_come_from_a_locked_sequence_per_category_and_year_never_max_plus_one(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $this->at('2026-09-21 06:00:00');
        $first = $this->activate($this->approved());
        $second = $this->activate($this->approved());
        $otherCategory = $this->activate($this->approved(category: 'V'));
        $this->at('2027-03-01 06:00:00');
        $nextYear = $this->activate($this->approved());

        $this->assertSame([1, 2, 1, 1], [$first->number_sequence, $second->number_sequence, $otherCategory->number_sequence, $nextYear->number_sequence]);
        $this->assertSame(2, DB::table('membership_number_sequences')->where('sequence_year', 2026)->where('membership_category_id', $first->membership_category_id)->value('last_number'));
        $this->assertSame(3, DB::table('membership_number_sequences')->count());

        $this->assertNotEmpty(array_filter($queries, fn (string $sql) => str_contains($sql, 'membership_number_sequences') && str_contains($sql, 'for update')));
        $this->assertEmpty(array_filter($queries, fn (string $sql) => str_contains($sql, 'max(')), 'The sequence must never use MAX()+1.');
    }

    public function test_a_failed_activation_consumes_no_number(): void
    {
        $this->at('2026-09-21 06:00:00');
        User::factory()->active()->create(['email' => 'taken@example.test']);
        $blocked = $this->approved(['email' => 'taken@example.test']);

        $this->assertThrows(fn () => $this->activate($blocked), ActivationEmailAlreadyInUseException::class);
        $this->assertSame(0, DB::table('membership_number_sequences')->count());

        $next = $this->activate($this->approved());
        $this->assertSame(1, $next->number_sequence, 'The next member still gets sequence 1: no gap.');
    }

    public function test_the_number_is_permanent(): void
    {
        $application = $this->approved();
        $membership = $this->activate($application);
        $number = $membership->membership_number;

        $this->assertThrows(fn () => $this->activate($application), MembershipCannotBeActivatedException::class);

        $this->assertSame($number, $membership->fresh()->membership_number);
        $this->assertSame($number, DB::table('memberships')->value('membership_number'));
        $this->assertSame(1, DB::table('memberships')->count());
    }

    // --- the account ----------------------------------------------------------

    public function test_the_member_account_is_created_pending_setup_with_a_setup_token(): void
    {
        $admin = $this->admin();
        $application = $this->approved(['full_name' => 'Nimal Perera', 'email' => 'nimal.member@example.test']);
        $usersBefore = DB::table('users')->count();

        $membership = $this->activate($application, $admin);

        $user = User::query()->where('email', 'nimal.member@example.test')->firstOrFail();
        $this->assertSame($usersBefore + 1, DB::table('users')->count());
        $this->assertSame('Nimal Perera', $user->name);
        $this->assertSame('member', $user->role);
        $this->assertSame('pending_setup', $user->status);
        $this->assertNull(DB::table('users')->where('id', $user->id)->value('password'));
        $this->assertNull($user->email_verified_at);
        $this->assertSame($user->id, $membership->user_id);

        $token = DB::table('account_setup_tokens')->first();
        $this->assertSame($user->id, $token->user_id);
        $this->assertSame($membership->id, $token->membership_id);
        $this->assertSame('account_setup', $token->purpose);
        $this->assertSame($admin->id, $token->issued_by_user_id);
        $this->assertSame(64, strlen($token->token_hash));
        $this->assertNull($token->used_at);
    }

    public function test_an_existing_user_with_the_same_email_blocks_activation_and_is_left_untouched(): void
    {
        foreach (['taken@example.test', 'TAKEN@Example.Test'] as $applicantEmail) {
            $existing = User::factory()->active()->create(['email' => 'taken@example.test', 'name' => 'Existing Person', 'role' => 'admin']);
            $before = DB::table('users')->where('id', $existing->id)->first();
            $application = $this->approved(['email' => $applicantEmail]);
            $counts = $this->counts();

            $this->assertThrows(fn () => $this->activate($application), ActivationEmailAlreadyInUseException::class);

            $this->assertEquals($before, DB::table('users')->where('id', $existing->id)->first(), 'The existing user must not change.');
            $this->assertSame($counts['users'], DB::table('users')->count());
            $this->assertSame(0, DB::table('memberships')->count());
            $this->assertSame(0, DB::table('membership_terms')->count());
            $this->assertSame(0, DB::table('account_setup_tokens')->count());
            $this->assertSame(0, DB::table('membership_number_sequences')->count());

            $existing->delete();
            $application->delete();
        }
    }

    // --- duplicates and concurrency -------------------------------------------

    public function test_a_second_activation_is_refused_and_creates_nothing(): void
    {
        $application = $this->approved();
        $this->activate($application);
        $before = $this->counts();

        $this->assertThrows(fn () => $this->activate($application), MembershipCannotBeActivatedException::class);

        $this->assertSame($before, $this->counts());
        $this->assertSame(1, DB::table('membership_number_sequences')->value('last_number'));
    }

    public function test_a_stale_copy_of_the_application_cannot_activate_it_again(): void
    {
        $application = $this->approved();
        $stale = MembershipApplication::query()->findOrFail($application->id);
        $this->activate($application);

        $this->assertSame('approved', $stale->status, 'The second admin still holds a stale approved copy.');
        $this->assertThrows(fn () => $this->activate($stale, $this->admin()), MembershipCannotBeActivatedException::class);

        $this->assertSame(1, DB::table('memberships')->count());
        $this->assertSame(1, DB::table('membership_terms')->count());
    }

    public function test_the_database_itself_refuses_a_second_member_for_one_application(): void
    {
        $application = $this->approved();
        $membership = $this->activate($application);

        $this->assertThrows(fn () => DB::table('memberships')->insert([
            'membership_application_id' => $application->id, 'user_id' => null,
            'membership_category_id' => $membership->membership_category_id, 'membership_number' => 'P26990009',
            'number_year' => 2026, 'number_sequence' => 9, 'activated_at' => now(), 'activated_on' => '2026-09-21',
        ]), UniqueConstraintViolationException::class);
    }

    // --- atomicity and retry --------------------------------------------------

    public function test_a_failure_while_creating_the_term_leaves_no_partial_records_and_a_retry_succeeds(): void
    {
        $this->at('2026-09-21 06:00:00');
        $application = $this->approved();
        $before = $this->counts();
        $failures = 1;
        MembershipTerm::creating(function () use (&$failures): void {
            if ($failures-- > 0) {
                throw new RuntimeException('Simulated failure creating the term.');
            }
        });

        $this->assertThrows(fn () => $this->activate($application), RuntimeException::class);

        $this->assertSame($before, $this->counts(), 'User, membership, sequence, token, history and audit must all roll back.');

        $membership = $this->activate($application);
        $this->assertSame(1, $membership->number_sequence, 'The retry gets sequence 1: the failed attempt consumed nothing.');
        $this->assertSame(1, DB::table('memberships')->count());
    }

    public function test_a_failure_while_issuing_the_setup_token_leaves_no_partial_records(): void
    {
        $application = $this->approved();
        $before = $this->counts();
        $this->mock(IssueAccountSetupToken::class, fn ($mock) => $mock->shouldReceive('handle')->once()->andThrow(new RuntimeException('Simulated token failure.')));

        $this->assertThrows(fn () => $this->activate($application), RuntimeException::class);

        $this->assertSame($before, $this->counts());
    }

    // --- history and audit ----------------------------------------------------

    public function test_the_documented_history_events_are_recorded_for_the_acting_admin(): void
    {
        $this->at('2026-09-21 06:00:00');
        $admin = $this->admin();
        $application = $this->approved();

        $membership = $this->activate($application, $admin);

        $history = DB::table('membership_status_history')->orderBy('id')->get();
        $this->assertSame(
            ['membership.activated', 'membership.number_issued', 'membership.introductory_term_started'],
            $history->pluck('event')->all(),
        );
        foreach ($history as $row) {
            $this->assertSame('admin', $row->actor_type);
            $this->assertSame($admin->id, $row->actor_user_id);
            $this->assertSame($membership->id, $row->membership_id);
        }
        $this->assertSame($application->id, $history[0]->membership_application_id);
        $this->assertSame($application->id, $history[1]->membership_application_id);
        $this->assertSame($membership->membership_number, $history[1]->note);
        $this->assertSame(DB::table('membership_terms')->value('id'), $history[2]->membership_term_id);
        $this->assertSame('active', $history[2]->to_status);
        $this->assertSame('2026-09-21 to 2027-03-20', $history[2]->note);
    }

    public function test_one_audit_row_records_the_activation_without_any_secret(): void
    {
        $admin = $this->admin();
        $membership = $this->activate($this->approved(), $admin);

        $audit = DB::table('audit_logs')->where('event', 'membership.activated')->get();
        $this->assertCount(1, $audit);
        $this->assertSame($admin->id, $audit[0]->user_id);
        $this->assertSame('user', $audit[0]->actor_type);
        $this->assertSame('membership', $audit[0]->subject_type);
        $this->assertSame($membership->id, $audit[0]->subject_id);

        $values = json_decode($audit[0]->new_values, true);
        $this->assertSame($membership->membership_number, $values['membership_number']);
        $this->assertSame(1, $values['term_no']);
        $tokenHash = DB::table('account_setup_tokens')->value('token_hash');
        $this->assertStringNotContainsString($tokenHash, $audit[0]->new_values);
    }

    // --- the one-time setup token ---------------------------------------------

    public function test_exactly_one_setup_token_is_issued_and_only_its_hash_is_stored(): void
    {
        $activated = $this->activateWithToken($this->approved());

        $this->assertSame(64, strlen($activated->setupToken));
        $this->assertSame(1, DB::table('account_setup_tokens')->count());
        $this->assertSame(hash('sha256', $activated->setupToken), DB::table('account_setup_tokens')->value('token_hash'));
        $this->assertNull(DB::table('account_setup_tokens')->whereNotNull('used_at')->first());

        foreach (DB::select('SHOW TABLES') as $row) {
            $table = array_values((array) $row)[0];
            $this->assertStringNotContainsString($activated->setupToken, DB::table($table)->get()->toJson(), "The plaintext token must not be stored in {$table}.");
        }
    }

    public function test_the_returned_token_cannot_leak_through_dumps_or_serialisation(): void
    {
        $activated = $this->activateWithToken($this->approved());

        $this->assertStringNotContainsString($activated->setupToken, print_r($activated, true));
        $this->assertStringNotContainsString($activated->setupToken, var_export($activated->membership->getKey(), true));
        $this->assertThrows(fn () => serialize($activated), LogicException::class);
    }

    public function test_the_action_itself_sends_nothing(): void
    {
        Notification::fake();
        Mail::fake();

        $this->activateWithToken($this->approved());

        Notification::assertNothingSent();
        Mail::assertNothingSent();
    }
}
