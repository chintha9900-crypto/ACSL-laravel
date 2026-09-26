<?php

namespace App\Notifications\Membership;

use App\Models\MembershipPlan;
use App\Models\MembershipTerm;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * M10 — a scheduled reminder before the current term's `expires_on`
 * (docs/architecture/06 §2). Configurable offsets (`config('membership.
 * renewal_reminder_days')`), never hard-coded; never implies auto-charge or
 * automatic renewal — it only points at the existing bank-transfer flow.
 */
class RenewalReminder extends Notification
{
    public function __construct(
        private readonly MembershipTerm $term,
        private readonly int $daysRemaining,
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
        $plan = MembershipPlan::query()->activeForCategory($this->term->membership->membership_category_id)->first();

        return (new MailMessage)
            ->subject('Your Aviation Club International membership expires soon')
            ->view(
                ['emails.membership.renewal-reminder', 'emails.membership.renewal-reminder-text'],
                [
                    'name' => $notifiable->name,
                    'number' => $this->term->membership->membership_number,
                    'daysRemaining' => $this->daysRemaining,
                    'expiresOn' => $this->term->expires_on?->format('j F Y'),
                    'fee' => $plan !== null ? $plan->currency.' '.number_format((float) $plan->fee_amount, 2) : null,
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
            'title' => 'Your membership expires soon',
            'message' => 'Your membership expires in '.$this->daysRemaining.' '.($this->daysRemaining === 1 ? 'day' : 'days').'. Renew now to avoid losing access.',
            'action_url' => route('member.membership.show'),
            'related_public_id' => null,
        ];
    }
}
