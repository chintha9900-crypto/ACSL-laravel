<?php

namespace App\Actions\Membership;

use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Send any member-facing notification after its triggering transaction has
 * committed. Never throws — a failed send is logged and swallowed, exactly
 * like `DeliverAccountSetupLink`/`DeliverWelcomeEmail`, so an email problem
 * never rolls back or blocks the business action that triggered it.
 */
class DeliverMemberNotification
{
    public function handle(User $user, Notification $notification): bool
    {
        try {
            $user->notify($notification);

            return true;
        } catch (Throwable $exception) {
            Log::warning('A member email could not be sent.', [
                'user_id' => $user->id,
                'notification' => $notification::class,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}
