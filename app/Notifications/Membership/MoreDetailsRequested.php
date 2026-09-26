<?php

namespace App\Notifications\Membership;

use App\Models\MembershipApplication;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * M2 — sent to the applicant when an admin requests more details
 * (docs/architecture/06 §2). Links back into the system to respond; never
 * "reply to this email" — the admin's internal `$note` is never included here,
 * only the applicant-facing `$requestMessage`.
 */
class MoreDetailsRequested extends Notification
{
    public function __construct(
        private readonly MembershipApplication $application,
        private readonly string $requestMessage,
    ) {}

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
            ->subject('More information needed for your Aviation Club International application')
            ->view(
                ['emails.membership.more-details-requested', 'emails.membership.more-details-requested-text'],
                [
                    'name' => $this->application->full_name,
                    'reference' => $this->application->public_id,
                    'requestMessage' => $this->requestMessage,
                    'url' => $this->application->statusUrl(),
                ],
            );
    }
}
