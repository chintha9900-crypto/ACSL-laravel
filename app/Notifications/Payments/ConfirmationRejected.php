<?php

namespace App\Notifications\Payments;

use App\Models\Payment;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * M7 — the admin rejected the submitted payment evidence
 * (docs/architecture/06 §2). States that resubmission is possible and the
 * renewal is not restarted (docs/database/06 §1: the rejection is transient).
 */
class ConfirmationRejected extends Notification
{
    public function __construct(
        private readonly Payment $payment,
        private readonly string $reason,
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
            ->subject('Your payment evidence needs another look')
            ->view(
                ['emails.payments.confirmation-rejected', 'emails.payments.confirmation-rejected-text'],
                [
                    'name' => $notifiable->name,
                    'reason' => $this->reason,
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
            'title' => 'Payment evidence rejected',
            'message' => 'Your payment evidence was not accepted. Please resubmit — your renewal has not been cancelled.',
            'action_url' => route('member.membership.show'),
            'related_public_id' => null,
        ];
    }
}
