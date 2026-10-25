<?php

namespace App\Notifications\Orders;

use App\Models\Order;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Sent once after checkout, to guests and members alike. Its link is how a
 * guest gets back to the order's payment page (to pay, or to upload bank
 * transfer evidence later) without relying on an open browser session: a
 * signed URL for a guest order, the plain route for a member's own order.
 * It confirms the order only — not payment.
 */
class OrderPlaced extends Notification
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
        $order = $this->order;
        $paymentUrl = $order->user_id === null ? URL::signedRoute('payment.show', $order) : route('payment.show', $order);
        $deadline = $order->expires_at?->copy()->setTimezone((string) config('membership.business_timezone'))->format('j F Y, g:ia');

        $message = (new MailMessage)
            ->subject(($order->is_test ? '[TEST] ' : '').'Order received — '.$order->order_number.' (payment required)')
            ->greeting('Hello '.$order->customer_name.',');

        if ($order->is_test) {
            $message->line('TEST ORDER — Dummy / Test Payment, Development Only. No real payment will be taken.');
        }

        $message->line('Thank you for your order '.$order->order_number.'. It is not confirmed until your payment is verified.');

        if ($order->subtotal_amount !== null) {
            $message->line('Items: '.$order->currency.' '.$order->subtotal_amount)
                ->line('Delivery (Sri Lanka): '.$order->currency.' '.$order->delivery_charge_amount);
        }

        $message->line('Total to pay: '.$order->currency.' '.$order->total_amount)
            ->line('Payment method: '.$order->paymentMethodLabel());

        if ($order->payment_method === Order::METHOD_BANK_TRANSFER) {
            $message->line('Use '.$order->order_number.' as the transfer reference. The bank details are on your payment page, where you can also upload your transfer evidence.');
        }

        if ($deadline !== null) {
            $message->line('Please complete payment by '.$deadline.' (Sri Lanka time), or the order will expire and the items will be released.');
        }

        return $message
            ->action('Open your payment page', $paymentUrl)
            ->line('Keep this email: the link above is how you return to your order.');
    }
}
