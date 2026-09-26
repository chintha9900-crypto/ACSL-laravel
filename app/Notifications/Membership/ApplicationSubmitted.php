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
        return (new MailMessage)
            ->subject("We've received your Aviation Club International application")
            ->view(
                ['emails.membership.application-submitted', 'emails.membership.application-submitted-text'],
                [
                    'name' => $this->application->full_name,
                    'reference' => $this->application->public_id,
                    'category' => $this->application->category->name,
                    'url' => $this->application->statusUrl(),
                ],
            );
    }
}
