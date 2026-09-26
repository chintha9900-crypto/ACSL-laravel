<?php

namespace App\Notifications\Membership;

use App\Models\MembershipTerm;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * M11 — the term has expired without a confirmed renewal
 * (docs/architecture/06 §2). States the approved 1-month grace period
 * (`config('membership.renewal_grace_period_months')`) and points at the
 * existing bank-transfer renewal flow. The membership number is retained —
 * never mentioned as changing, because it never does.
 */
class Expired extends Notification
{
    public function __construct(
        private readonly MembershipTerm $term,
        private readonly string $graceEndsOn,
    ) {}

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
            ->subject('Your Aviation Club International membership has expired')
            ->view(
                ['emails.membership.expired', 'emails.membership.expired-text'],
                [
                    'name' => $notifiable->name,
                    'number' => $this->term->membership->membership_number,
                    'graceEndsOn' => $this->graceEndsOn,
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
            'title' => 'Your membership has expired',
            'message' => 'Your membership has expired. Renew by '.$this->graceEndsOn.' to restore your benefits.',
            'action_url' => route('member.membership.show'),
            'related_public_id' => null,
        ];
    }
}
