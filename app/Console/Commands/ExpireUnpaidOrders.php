<?php

namespace App\Console\Commands;

use App\Actions\Orders\ExpireUnpaidOrder;
use App\Models\Order;
use Illuminate\Console\Command;

/**
 * Expires e-shop orders still unpaid after their payment deadline (30 minutes
 * for PayHere, 72 hours for bank transfer, from order creation) and returns
 * their reserved stock. Deadlines are stored and compared in UTC.
 *
 * Idempotent: each order is re-checked under its own row lock
 * (ExpireUnpaidOrder), so overlapping or repeated runs never expire an order
 * twice or release its stock twice.
 */
class ExpireUnpaidOrders extends Command
{
    protected $signature = 'orders:expire-unpaid';

    protected $description = 'Expire unpaid e-shop orders past their payment deadline and release their stock';

    public function handle(ExpireUnpaidOrder $expire): int
    {
        $expired = 0;

        Order::query()
            ->where('status', Order::STATUS_PENDING_PAYMENT)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->select(['id'])
            ->chunkById(100, function ($orders) use ($expire, &$expired): void {
                foreach ($orders as $order) {
                    if ($expire->handle($order)) {
                        $expired++;
                    }
                }
            });

        $this->info("Expired {$expired} unpaid order(s).");

        return self::SUCCESS;
    }
}
