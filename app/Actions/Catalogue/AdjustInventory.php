<?php

namespace App\Actions\Catalogue;

use App\Exceptions\InsufficientStockException;
use App\Models\Inventory;
use Illuminate\Support\Facades\DB;

/**
 * Stock changes a concurrent admin edit (or, later, a checkout) could race
 * against: each runs inside its own transaction with the inventory row
 * locked (`lockForUpdate()`), so the quantity read and the quantity written
 * are never stale by the time of the write — the same "lock the row, then
 * read-check-write inside one transaction" pattern documented for the
 * deferred stock ledger (docs/database/12_ECOMMERCE_SCHEMA.md §7.2), just
 * without the ledger itself.
 */
class AdjustInventory
{
    public function add(Inventory $inventory, int $amount): Inventory
    {
        return DB::transaction(function () use ($inventory, $amount): Inventory {
            $locked = Inventory::query()->lockForUpdate()->findOrFail($inventory->id);
            $locked->quantity += $amount;
            $locked->save();

            return $locked;
        });
    }

    /**
     * @throws InsufficientStockException if `$amount` would take stock below zero.
     */
    public function remove(Inventory $inventory, int $amount): Inventory
    {
        return DB::transaction(function () use ($inventory, $amount): Inventory {
            $locked = Inventory::query()->lockForUpdate()->findOrFail($inventory->id);

            if ($amount > $locked->quantity) {
                throw new InsufficientStockException("Only {$locked->quantity} in stock — cannot remove {$amount}.");
            }

            $locked->quantity -= $amount;
            $locked->save();

            return $locked;
        });
    }
}
