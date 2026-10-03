<?php

namespace App\Actions\Orders;

use App\Exceptions\InvalidOrderStatusTransitionException;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * E-Shop Step 9 — the only place an admin's fulfilment-status change is
 * ever written. Writes `orders.status` only — never `payments.status`,
 * never an `order_items` snapshot column, never `Inventory` — so payment
 * status and order status stay logically separate, historical snapshots
 * stay immutable, and inventory behaviour is unchanged by this step (see
 * the class doc on `Order::TRANSITIONS` for why `pending_payment` → `paid`
 * is deliberately not a transition this Action can ever make).
 *
 * Stock restoration on cancellation/refund was evaluated and deliberately
 * NOT implemented here — see docs/database/12_ECOMMERCE_SCHEMA.md §7.2:
 * there is no inventory ledger yet, so a direct `Inventory::increment()`
 * would have no audit trail for *why* stock changed, even though it would
 * be numerically safe. Flagged for an explicit decision before building it,
 * rather than inventing a partial solution now.
 */
class UpdateOrderStatus
{
    /**
     * @throws InvalidOrderStatusTransitionException
     */
    public function handle(Order $order, string $status): Order
    {
        return DB::transaction(function () use ($order, $status): Order {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            if (! $locked->canTransitionTo($status)) {
                throw new InvalidOrderStatusTransitionException(
                    "Cannot move an order from \"{$locked->status}\" to \"{$status}\"."
                );
            }

            $locked->update(['status' => $status]);

            return $locked;
        });
    }
}
