<?php

namespace App\Notifications\Payments;

use App\Models\Payment;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * M6 — acknowledges that the member's renewal payment evidence was submitted
 * and is awaiting admin review (docs/architecture/06 §2).
 */
class ConfirmationSubmitted extends Notification
{
    public function __construct(private readonly Payment $payment) {}

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
            ->subject('We have received your payment evidence')
            ->view(
                ['emails.payments.confirmation-submitted', 'emails.payments.confirmation-submitted-text'],
                [
                    'name' => $notifiable->name,
                    'amount' => number_format((float) $this->payment->amount, 2),
                    'currency' => $this->payment->currency,
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
            'title' => 'Payment evidence received',
            'message' => 'Your payment evidence has been submitted and is awaiting review.',
            'action_url' => route('member.membership.show'),
            'related_public_id' => null,
        ];
    }
}
