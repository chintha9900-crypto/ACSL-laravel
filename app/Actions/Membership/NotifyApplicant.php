<?php

namespace App\Actions\Membership;

use App\Models\MembershipApplication;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Throwable;

/**
 * Email an applicant who has no user account yet (M1–M4: submitted, more details
 * requested, rejected, approved). Uses Laravel's on-demand/anonymous notifiable —
 * the same `Notification` classes and `mail` channel as `AccountSetup`, just
 * routed by email address instead of a `User` model.
 *
 * Never throws: a failed send is logged and swallowed, exactly like
 * `DeliverAccountSetupLink`, so an email problem never breaks the business
 * action (submission or review) that triggered it.
 */
class NotifyApplicant
{
    public function handle(MembershipApplication $application, Notification $notification): bool
    {
        try {
            NotificationFacade::route('mail', $application->email)->notify($notification);

            return true;
        } catch (Throwable $exception) {
            Log::warning('An applicant email could not be sent.', [
                'membership_application_id' => $application->id,
                'notification' => $notification::class,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}
