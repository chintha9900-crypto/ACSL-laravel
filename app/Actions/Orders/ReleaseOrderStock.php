<?php

namespace App\Actions\Orders;

use App\Models\Inventory;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Returns an order's reserved stock (decremented at checkout by PlaceOrder)
 * to inventory, exactly once: `orders.stock_released_at` is set under the
 * order's row lock and checked before anything is incremented.
 *
 * Orders with a settled payment are refused — stock for goods that were paid
 * for is only returned through a refund/return process. The exception is a
 * simulated test order (`is_test`), whose stock is always returned so testing
 * never consumes real inventory.
 *
 * Lock order: order → inventory (callers already holding the order lock
 * re-acquire it in the same transaction at no cost).
 */
class ReleaseOrderStock
{
    public function __construct(private readonly RecordOrderAudit $audit) {}

    /**
     * @return bool Whether stock was released by this call.
     */
    public function handle(Order $order, ?User $actor, string $reason): bool
    {
        return DB::transaction(function () use ($order, $actor, $reason): bool {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($locked->stock_released_at !== null) {
                return false;
            }

            if (! $locked->is_test && $locked->payments()->whereIn('status', Payment::SETTLED_STATUSES)->exists()) {
                throw new LogicException('Stock for a paid order is not released without a refund process.');
            }

            $released = [];

            foreach ($locked->items()->whereNotNull('product_id')->orderBy('product_id')->get() as $item) {
                $inventory = Inventory::query()->where('product_id', $item->product_id)->lockForUpdate()->first();

                if ($inventory === null) {
                    continue;
                }

                $inventory->increment('quantity', $item->quantity);
                $released[$item->sku] = $item->quantity;
            }

            $locked->forceFill(['stock_released_at' => now()])->save();

            $this->audit->handle($locked, 'order.stock_released', $actor, [
                'reason' => $reason,
                'quantities' => $released,
            ], $actor === null ? RecordOrderAudit::ACTOR_SYSTEM : null);

            return true;
        }, 3);
    }
}
