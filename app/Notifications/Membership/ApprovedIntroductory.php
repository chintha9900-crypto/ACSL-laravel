<?php

namespace App\Notifications\Membership;

use App\Models\MembershipApplication;
use App\Models\MembershipSetting;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * M4 — sent to the applicant when their application is approved
 * (docs/architecture/06 §2). Confirms approval and that the first N months are
 * free; N is read from `membership_settings` here because approval happens
 * before activation (OD-23) — no `MembershipTerm` exists yet to read it from.
 * Not a promotion email; mandatory for every approval. Activation (M8/M9)
 * follows as a separate, later step and is not promised on any schedule here.
 */
class ApprovedIntroductory extends Notification
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
        $months = (int) MembershipSetting::current()->introductory_period_months;

        return (new MailMessage)
            ->subject('Your Aviation Club International application has been approved')
            ->view(
                ['emails.membership.approved-introductory', 'emails.membership.approved-introductory-text'],
                [
                    'name' => $this->application->full_name,
                    'category' => $this->application->category->name,
                    'months' => $months,
                ],
            );
    }
}
