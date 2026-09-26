<?php

namespace App\Notifications\Payments;

use App\Models\MembershipTerm;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent when an admin confirms a renewal payment and the renewal term becomes
 * active. Not one of the numbered M1–M11 messages in
 * docs/architecture/06_NOTIFICATION_ARCHITECTURE.md (that catalogue has no
 * "renewal confirmed" entry — only the free-path M8/M9 activation emails), but
 * explicitly required by this phase's own notification list ("payment
 * confirmed"). The membership number never changes at renewal, so it is shown
 * unchanged here for reassurance, not as new information.
 */
class PaymentConfirmed extends Notification
{
    public function __construct(private readonly MembershipTerm $term) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your Aviation Club International renewal is confirmed')
            ->view(
                ['emails.payments.payment-confirmed', 'emails.payments.payment-confirmed-text'],
                [
                    'name' => $notifiable->name,
                    'number' => $this->term->membership->membership_number,
                    'expiresOn' => $this->term->expires_on?->format('j F Y'),
                    'url' => route('member.membership.show'),
                ],
            );
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Renewal confirmed',
            'message' => 'Your membership renewal is confirmed, valid until '.$this->term->expires_on?->format('j F Y').'.',
            'action_url' => route('member.membership.show'),
            'related_public_id' => null,
        ];
    }
}
