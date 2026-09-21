<?php

namespace App\Actions\Auth;

use App\Models\AccountSetupToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CompleteAccountSetup
{
    /**
     * Whether a plaintext token would currently be accepted.
     */
    public function isUsable(string $plainToken): bool
    {
        $token = AccountSetupToken::query()
            ->usable()
            ->where('purpose', AccountSetupToken::PURPOSE_ACCOUNT_SETUP)
            ->where('token_hash', AccountSetupToken::hashFor($plainToken))
            ->first();

        return $token !== null && $token->user->status === 'pending_setup';
    }

    /**
     * Consume a token: set the member's first password and activate the account.
     *
     * Returns false, without changing anything, when the token is unknown,
     * expired, already used or invalidated, or when the user is no longer
     * pending setup (a token never changes an already-active account's
     * credentials). The token row and the user row are locked and re-checked
     * inside the transaction, so two concurrent submissions cannot both succeed.
     */
    public function handle(string $plainToken, string $password): bool
    {
        return DB::transaction(function () use ($plainToken, $password): bool {
            $token = AccountSetupToken::query()
                ->usable()
                ->where('purpose', AccountSetupToken::PURPOSE_ACCOUNT_SETUP)
                ->where('token_hash', AccountSetupToken::hashFor($plainToken))
                ->lockForUpdate()
                ->first();

            if ($token === null) {
                return false;
            }

            $user = User::query()->whereKey($token->user_id)->lockForUpdate()->first();

            if ($user === null || $user->status !== 'pending_setup') {
                return false;
            }

            $user->forceFill([
                'password' => $password,
                'status' => 'active',
                'email_verified_at' => now(),
                'remember_token' => Str::random(60),
            ])->save();

            $token->forceFill(['used_at' => now()])->save();

            return true;
        });
    }
}
