<?php

namespace App\Actions\Orders;

use App\Models\Order;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Throwable;

/**
 * Email an order's customer by their own stored `customer_email` — the same
 * on-demand/anonymous notifiable pattern `Membership\NotifyApplicant` uses,
 * since an order's customer may or may not have a user account.
 *
 * Never throws: a failed send is logged and swallowed, so an email problem
 * never undoes or interrupts the business action (here, payment
 * confirmation) that triggered it.
 */
class NotifyCustomer
{
    public function handle(Order $order, Notification $notification): bool
    {
        try {
            NotificationFacade::route('mail', $order->customer_email)->notify($notification);

            return true;
        } catch (Throwable $exception) {
            Log::warning('An order customer email could not be sent.', [
                'order_id' => $order->id,
                'notification' => $notification::class,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}
