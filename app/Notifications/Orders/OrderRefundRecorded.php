<?php

namespace App\Notifications\Orders;

use App\Models\Order;
use App\Models\PaymentRefund;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent once per recorded refund (each refund record is unique per payment and
 * reference, so a repeated request records nothing and sends nothing).
 */
class OrderRefundRecorded extends Notification
{
    public function __construct(
        private readonly Order $order,
        private readonly PaymentRefund $refund,
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
        $fullyRefunded = $this->order->status === Order::STATUS_REFUNDED;

        return (new MailMessage)
            ->subject(($this->order->is_test ? '[TEST] ' : '').'Refund for your order '.$this->order->order_number)
            ->greeting('Hello '.$this->order->customer_name.',')
            ->line('We have refunded '.$this->refund->currency.' '.$this->refund->amount.' for order '.$this->order->order_number.'.')
            ->line('Refund reference: '.$this->refund->refund_reference.' (refunded on '.$this->refund->refunded_on->format('j F Y').').')
            ->line($fullyRefunded
                ? 'Your order has now been refunded in full.'
                : 'This is a partial refund of the amount you paid.')
            ->line('Depending on your bank or card provider, it may take several working days for the refund to appear in your account.');
    }
}
