<?php

namespace App\Notifications\Orders;

use App\Models\Order;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent once when an unpaid order passes its payment deadline and expires.
 */
class OrderExpired extends Notification
{
    public function __construct(private readonly Order $order) {}

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
            ->subject(($this->order->is_test ? '[TEST] ' : '').'Your order '.$this->order->order_number.' has expired')
            ->greeting('Hello '.$this->order->customer_name.',')
            ->line('We did not receive payment for order '.$this->order->order_number.' before its payment deadline, so the order has expired and the items were released.')
            ->line('If you have already paid, please contact us with your payment reference and we will look into it.')
            ->action('Visit the e-shop', route('eshop.index'));
    }
}
