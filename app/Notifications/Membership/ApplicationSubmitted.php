<?php

namespace App\Notifications\Membership;

use App\Models\MembershipApplication;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * M1 — sent to the applicant when their application is submitted
 * (docs/architecture/06 §2). The applicant has no user account yet, so this is
 * always delivered via `NotifyApplicant` (an anonymous/on-demand notifiable),
 * never `$user->notify()`. Mail only: there is no account to hold an in-app row.
 */
class ApplicationSubmitted extends Notification
{
    public function __construct(private readonly MembershipApplication $application) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // The body is fixed, approved copy with no applicant-specific detail
        // (not even a name) beyond the one thing explicitly requested: the
        // application's own existing signed status link — no new URL/token
        // system, just `MembershipApplication::statusUrl()` as already used
        // elsewhere (e.g. the applicant-facing status page itself).
        return (new MailMessage)
            ->subject('Application Received — Aviation Club')
            ->view(
                ['emails.membership.application-submitted', 'emails.membership.application-submitted-text'],
                ['url' => $this->application->statusUrl()],
            );
    }
}
