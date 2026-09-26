<?php

namespace App\Actions\Membership;

use App\Models\Membership;
use App\Notifications\Membership\Welcome;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Send the M8 welcome email (and its matching in-app notification) after
 * activation. Never throws: a failed send is logged and swallowed, exactly
 * like `DeliverAccountSetupLink`, so it never affects the activation response.
 */
class DeliverWelcomeEmail
{
    public function handle(Membership $membership): bool
    {
        try {
            $membership->user->notify(new Welcome($membership));

            return true;
        } catch (Throwable $exception) {
            Log::warning('The welcome email could not be sent.', [
                'membership_id' => $membership->id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}
