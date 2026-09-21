<?php

namespace App\Actions\Auth;

use App\Models\AccountSetupToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class IssueAccountSetupToken
{
    /**
     * Issue a one-time account setup token for a provisioned user.
     *
     * Returns the plaintext token, which must only ever be placed in the link
     * given to the member; the database keeps only its SHA-256 hash. Any earlier
     * live token for the same user is invalidated in the same transaction.
     */
    public function handle(User $user, int $membershipId, ?User $issuedBy = null): string
    {
        if ($user->status !== 'pending_setup') {
            throw new LogicException('Account setup tokens can only be issued for a user pending setup.');
        }

        $plainToken = Str::random(64);

        DB::transaction(function () use ($user, $membershipId, $issuedBy, $plainToken): void {
            DB::table('membership_settings')->insertOrIgnore([
                'id' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $ttlHours = (int) DB::table('membership_settings')
                ->where('id', 1)
                ->value('account_setup_token_ttl_hours');

            AccountSetupToken::query()
                ->where('user_id', $user->id)
                ->where('purpose', AccountSetupToken::PURPOSE_ACCOUNT_SETUP)
                ->whereNull('used_at')
                ->whereNull('invalidated_at')
                ->update([
                    'invalidated_at' => now(),
                    'invalidated_reason' => 'superseded',
                ]);

            AccountSetupToken::create([
                'user_id' => $user->id,
                'membership_id' => $membershipId,
                'purpose' => AccountSetupToken::PURPOSE_ACCOUNT_SETUP,
                'token_hash' => AccountSetupToken::hashFor($plainToken),
                'expires_at' => now()->addHours($ttlHours),
                'issued_by_user_id' => $issuedBy?->id,
            ]);
        });

        return $plainToken;
    }
}
