<?php

namespace App\Notifications\Membership;

use App\Models\Membership;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * M8 — sent to the member when their membership is activated
 * (docs/architecture/06 §2). Carries the membership number, category and the
 * introductory term's dates; the one-time setup link is M9's job, not this
 * one's, so it is never referenced here beyond "check your email".
 */
class Welcome extends Notification
{
    public function __construct(private readonly Membership $membership) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $term = $this->membership->terms->first();

        return (new MailMessage)
            ->subject('Welcome to Aviation Club International')
            ->view(
                ['emails.membership.welcome', 'emails.membership.welcome-text'],
                [
                    'name' => $notifiable->name,
                    'number' => $this->membership->membership_number,
                    'category' => $this->membership->category->name,
                    'months' => $term?->duration_months,
                    'startsOn' => $term?->starts_on?->format('j F Y'),
                    'expiresOn' => $term?->expires_on?->format('j F Y'),
                ],
            );
    }

    /**
     * The in-app inbox row. No secrets to withhold here (unlike AccountSetup),
     * but still no `related_public_id`: `Membership` has no `public_id` column,
     * only the lifetime `membership_number`, which is not a routable identifier.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Welcome to Aviation Club International',
            'message' => 'Your membership ('.$this->membership->membership_number.') is now active.',
            'action_url' => route('member.membership.show'),
            'related_public_id' => null,
        ];
    }
}
