<?php

namespace Tests\Feature\Actions\Auth;

use App\Actions\Auth\IssueAccountSetupToken;
use App\Models\AccountSetupToken;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Concerns\CreatesMembershipRecords;
use Tests\MysqlTestCase;

class IssueAccountSetupTokenTest extends MysqlTestCase
{
    use CreatesMembershipRecords;

    public function test_stores_only_a_sha256_hash_of_the_returned_plaintext_token(): void
    {
        $user = User::factory()->pendingSetup()->create();
        $membershipId = $this->createMembershipFor($user);

        $plain = app(IssueAccountSetupToken::class)->handle($user, $membershipId);

        $this->assertGreaterThanOrEqual(40, strlen($plain));

        $row = DB::table('account_setup_tokens')->where('user_id', $user->id)->first();
        $this->assertSame(hash('sha256', $plain), $row->token_hash);
        $this->assertNotSame($plain, $row->token_hash);
        $this->assertNull($row->used_at);
        $this->assertNull($row->invalidated_at);

        $this->assertSame(
            0,
            DB::table('account_setup_tokens')->where('token_hash', $plain)->count(),
            'The plaintext token must not be stored.'
        );
    }

    public function test_token_expires_after_the_configured_number_of_hours(): void
    {
        $this->freezeTime();
        $user = User::factory()->pendingSetup()->create();
        $membershipId = $this->createMembershipFor($user);

        app(IssueAccountSetupToken::class)->handle($user, $membershipId);

        $ttlHours = (int) DB::table('membership_settings')->where('id', 1)->value('account_setup_token_ttl_hours');
        $token = AccountSetupToken::query()->where('user_id', $user->id)->firstOrFail();

        $this->assertSame(72, $ttlHours);
        $this->assertSame(now()->addHours(72)->toDateTimeString(), $token->expires_at->toDateTimeString());
    }

    public function test_uses_a_changed_ttl_from_membership_settings(): void
    {
        $this->freezeTime();
        $user = User::factory()->pendingSetup()->create();
        $membershipId = $this->createMembershipFor($user);

        DB::table('membership_settings')->insertOrIgnore(['id' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('membership_settings')->where('id', 1)->update(['account_setup_token_ttl_hours' => 24]);

        app(IssueAccountSetupToken::class)->handle($user, $membershipId);

        $token = AccountSetupToken::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame(now()->addHours(24)->toDateTimeString(), $token->expires_at->toDateTimeString());
    }

    public function test_reissuing_invalidates_the_previous_live_token(): void
    {
        $user = User::factory()->pendingSetup()->create();
        $membershipId = $this->createMembershipFor($user);
        $issue = app(IssueAccountSetupToken::class);

        $first = $issue->handle($user, $membershipId);
        $second = $issue->handle($user, $membershipId);

        $firstRow = AccountSetupToken::query()->where('token_hash', hash('sha256', $first))->firstOrFail();
        $secondRow = AccountSetupToken::query()->where('token_hash', hash('sha256', $second))->firstOrFail();

        $this->assertNotNull($firstRow->invalidated_at);
        $this->assertSame('superseded', $firstRow->invalidated_reason);
        $this->assertNull($secondRow->invalidated_at);
    }

    public function test_database_allows_at_most_one_live_token_per_user_and_purpose(): void
    {
        $user = User::factory()->pendingSetup()->create();
        $membershipId = $this->createMembershipFor($user);
        app(IssueAccountSetupToken::class)->handle($user, $membershipId);

        $this->expectException(QueryException::class);

        DB::table('account_setup_tokens')->insert([
            'user_id' => $user->id,
            'membership_id' => $membershipId,
            'purpose' => 'account_setup',
            'token_hash' => hash('sha256', 'a-second-live-token'),
            'expires_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_refuses_to_issue_a_token_for_a_user_who_is_not_pending_setup(): void
    {
        $user = User::factory()->active()->create();
        $membershipId = $this->createMembershipFor($user);

        $this->expectException(LogicException::class);

        app(IssueAccountSetupToken::class)->handle($user, $membershipId);
    }
}
