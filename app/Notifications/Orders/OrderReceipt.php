<?php

namespace App\Notifications\Orders;

use App\Models\Order;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * E-Shop Step 8 — sent exactly once, only when `Actions\Payments\
 * ConfirmOrderPayment` first transitions a payment to `paid` (never for a
 * pending, failed or cancelled payment, and never replayed for an
 * already-paid one — see that Action's own idempotency guard). Every value
 * here comes from the order's own stored columns and its `order_items`
 * snapshots — never a live product lookup — so the email stays correct
 * even if the catalogue changes afterwards. Carries no payment credentials
 * or gateway details, only the fact that payment succeeded.
 */
class OrderReceipt extends Notification
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
            ->subject('Your Aviation Club International order receipt — '.$this->order->order_number)
            ->view(
                ['emails.orders.receipt', 'emails.orders.receipt-text'],
                [
                    'customerName' => $this->order->customer_name,
                    'orderNumber' => $this->order->order_number,
                    'orderDate' => $this->order->created_at->format('j F Y, g:ia'),
                    'currency' => $this->order->currency,
                    'items' => $this->order->items,
                    'totalAmount' => $this->order->total_amount,
                ],
            );
    }
}
