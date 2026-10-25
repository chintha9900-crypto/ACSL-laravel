<?php

namespace App\Notifications\Orders;

use App\Models\Order;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Sent when an admin rejects the bank-transfer evidence for an e-shop order.
 * The order stays awaiting payment and the link lets the customer resubmit
 * (signed for guest orders, the same access rule as the order pages).
 */
class OrderPaymentRejected extends Notification
{
    public function __construct(
        private readonly Order $order,
        private readonly string $reason,
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
        $paymentUrl = $this->order->user_id === null
            ? URL::signedRoute('payment.show', $this->order)
            : route('payment.show', $this->order);

        return (new MailMessage)
            ->subject('We could not verify your payment — order '.$this->order->order_number)
            ->greeting('Hello '.$this->order->customer_name.',')
            ->line('We could not verify the bank transfer details you submitted for order '.$this->order->order_number.'.')
            ->line('Reason: '.$this->reason)
            ->line('Your order is still open. You can submit corrected transfer details from the payment page.')
            ->action('Open payment page', $paymentUrl);
    }
}
