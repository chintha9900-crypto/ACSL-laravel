<?php

namespace Tests\Feature\Actions\Auth;

use App\Actions\Auth\CompleteAccountSetup;
use App\Actions\Auth\IssueAccountSetupToken;
use App\Models\AccountSetupToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesMembershipRecords;
use Tests\MysqlTestCase;

class CompleteAccountSetupTest extends MysqlTestCase
{
    use CreatesMembershipRecords;

    /**
     * @return array{User, int, string}
     */
    private function provisionUserWithToken(): array
    {
        $user = User::factory()->pendingSetup()->create();
        $membershipId = $this->createMembershipFor($user);
        $plain = app(IssueAccountSetupToken::class)->handle($user, $membershipId);

        return [$user, $membershipId, $plain];
    }

    public function test_sets_a_hashed_password_activates_the_user_and_consumes_the_token(): void
    {
        [$user, , $plain] = $this->provisionUserWithToken();

        $this->assertTrue(app(CompleteAccountSetup::class)->handle($plain, 'My-First-Passw0rd'));

        $fresh = $user->fresh();
        $stored = DB::table('users')->where('id', $user->id)->value('password');

        $this->assertSame('active', $fresh->status);
        $this->assertNotNull($fresh->email_verified_at);
        $this->assertNotSame('My-First-Passw0rd', $stored);
        $this->assertTrue(Hash::check('My-First-Passw0rd', $stored));
        $this->assertNotNull(AccountSetupToken::query()->where('user_id', $user->id)->value('used_at'));
    }

    public function test_token_cannot_be_used_a_second_time_after_the_password_is_set(): void
    {
        [$user, , $plain] = $this->provisionUserWithToken();
        $setup = app(CompleteAccountSetup::class);

        $this->assertTrue($setup->handle($plain, 'My-First-Passw0rd'));
        $this->assertFalse($setup->handle($plain, 'An-Attackers-Passw0rd'));
        $this->assertFalse($setup->isUsable($plain));

        $this->assertTrue(Hash::check('My-First-Passw0rd', DB::table('users')->where('id', $user->id)->value('password')));
    }

    public function test_used_token_stays_dead_even_if_the_user_is_pending_setup_again(): void
    {
        [$user, , $plain] = $this->provisionUserWithToken();
        $setup = app(CompleteAccountSetup::class);
        $this->assertTrue($setup->handle($plain, 'My-First-Passw0rd'));

        $user->refresh()->forceFill(['status' => 'pending_setup', 'password' => null])->save();

        $this->assertFalse($setup->isUsable($plain));
        $this->assertFalse($setup->handle($plain, 'An-Attackers-Passw0rd'));
        $this->assertNull(DB::table('users')->where('id', $user->id)->value('password'));
    }

    public function test_expired_token_is_rejected_and_nothing_changes(): void
    {
        [$user, , $plain] = $this->provisionUserWithToken();
        $setup = app(CompleteAccountSetup::class);

        $this->travel(71)->hours();
        $this->travel(59)->minutes();
        $this->assertTrue($setup->isUsable($plain), 'Still valid just before the 72 hour limit.');

        $this->travel(2)->minutes();
        $this->assertFalse($setup->isUsable($plain));
        $this->assertFalse($setup->handle($plain, 'My-First-Passw0rd'));

        $this->assertNull(DB::table('users')->where('id', $user->id)->value('password'));
        $this->assertSame('pending_setup', $user->fresh()->status);
    }

    public function test_superseded_token_is_rejected_but_the_new_one_works(): void
    {
        [$user, $membershipId, $oldToken] = $this->provisionUserWithToken();
        $newToken = app(IssueAccountSetupToken::class)->handle($user, $membershipId);
        $setup = app(CompleteAccountSetup::class);

        $this->assertFalse($setup->handle($oldToken, 'My-First-Passw0rd'));
        $this->assertTrue($setup->handle($newToken, 'My-First-Passw0rd'));
    }

    public function test_unknown_token_is_rejected(): void
    {
        $this->assertFalse(app(CompleteAccountSetup::class)->handle('not-a-real-token', 'My-First-Passw0rd'));
    }

    public function test_token_never_changes_the_credentials_of_an_already_active_user(): void
    {
        $user = User::factory()->active()->create(['password' => 'Original-Passw0rd']);
        $membershipId = $this->createMembershipFor($user);

        DB::table('account_setup_tokens')->insert([
            'user_id' => $user->id,
            'membership_id' => $membershipId,
            'purpose' => 'account_setup',
            'token_hash' => hash('sha256', 'forced-token-for-active-user'),
            'expires_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertFalse(app(CompleteAccountSetup::class)->handle('forced-token-for-active-user', 'Attacker-Passw0rd'));

        $this->assertTrue(Hash::check('Original-Passw0rd', DB::table('users')->where('id', $user->id)->value('password')));
    }

    public function test_membership_link_purpose_tokens_are_not_redeemable_here(): void
    {
        $user = User::factory()->pendingSetup()->create();
        $membershipId = $this->createMembershipFor($user);

        DB::table('account_setup_tokens')->insert([
            'user_id' => $user->id,
            'membership_id' => $membershipId,
            'purpose' => 'membership_link',
            'token_hash' => hash('sha256', 'link-purpose-token'),
            'expires_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertFalse(app(CompleteAccountSetup::class)->handle('link-purpose-token', 'My-First-Passw0rd'));
        $this->assertNull(DB::table('users')->where('id', $user->id)->value('password'));
    }
}
