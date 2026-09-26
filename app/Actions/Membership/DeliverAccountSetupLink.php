<?php

namespace App\Actions\Membership;

use App\Models\AccountSetupToken;
use App\Models\User;
use App\Notifications\Membership\AccountSetup;
use Illuminate\Support\Facades\Log;
use LogicException;
use SensitiveParameter;
use Throwable;

class DeliverAccountSetupLink
{
    /**
     * Email the setup link for an already-issued token, synchronously.
     *
     * Call only after the transaction that issued the token has committed. A delivery
     * failure never propagates: it returns false, and the log records the user id and
     * the error with the token redacted, so the caller can tell the admin without
     * exposing transport details.
     */
    public function handle(User $user, #[SensitiveParameter] string $plainToken): bool
    {
        try {
            $expiresAt = AccountSetupToken::query()
                ->where('token_hash', AccountSetupToken::hashFor($plainToken))
                ->value('expires_at');

            if ($expiresAt === null) {
                throw new LogicException('No setup token record exists for this delivery.');
            }

            $user->notify(new AccountSetup($plainToken, $expiresAt));

            return true;
        } catch (Throwable $exception) {
            Log::warning('Account setup email could not be sent.', [
                'user_id' => $user->id,
                'exception' => $exception::class,
                'message' => str_replace($plainToken, '[redacted]', $exception->getMessage()),
            ]);

            return false;
        }
    }
}
