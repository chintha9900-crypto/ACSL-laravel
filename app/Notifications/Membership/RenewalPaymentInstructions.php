<?php

namespace App\Notifications\Membership;

use App\Models\MembershipTerm;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * M5 — sent when a member starts a renewal (docs/architecture/06 §2). Bank
 * details and the fee are read from the term/payment at send time, never
 * hard-coded, so a later change to the bank account never rewrites history
 * (docs/database/06 §6) — the payment already snapshots which account it named.
 */
class RenewalPaymentInstructions extends Notification
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
        $payment = $this->term->payment;
        $bank = $payment->bankAccount;

        return (new MailMessage)
            ->subject('Renew your Aviation Club International membership')
            ->view(
                ['emails.membership.renewal-payment-instructions', 'emails.membership.renewal-payment-instructions-text'],
                [
                    'name' => $notifiable->name,
                    'number' => $this->term->membership->membership_number,
                    'amount' => number_format((float) $payment->amount, 2),
                    'currency' => $payment->currency,
                    'months' => $this->term->duration_months,
                    'bank' => $bank,
                    'url' => route('member.membership.show'),
                ],
            );
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $payment = $this->term->payment;

        return [
            'title' => 'Renew your membership',
            'message' => 'Your renewal fee is '.$payment->currency.' '.number_format((float) $payment->amount, 2).'. Pay by bank transfer and submit your evidence.',
            'action_url' => route('member.membership.show'),
            'related_public_id' => null,
        ];
    }
}
