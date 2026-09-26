<?php

namespace App\Notifications\Membership;

use App\Models\MembershipApplication;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * M3 — sent to the applicant when an admin declines their application
 * (docs/architecture/06 §2). The admin's internal `decision_note` is never
 * shown to the applicant (docs/database/04 §11) — it is deliberately not
 * passed to, or used by, this class.
 */
class ApplicationRejected extends Notification
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
            ->subject('Your Aviation Club International application')
            ->view(
                ['emails.membership.application-rejected', 'emails.membership.application-rejected-text'],
                [
                    'name' => $this->application->full_name,
                    'reference' => $this->application->public_id,
                    'applyUrl' => route('membership.apply'),
                ],
            );
    }
}
