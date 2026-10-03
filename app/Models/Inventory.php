<?php

namespace App\Models;

use Database\Factories\InventoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per product. `quantity` must never go negative — enforced at the
 * database layer by the `inventories_quantity_non_negative` CHECK
 * constraint, not just application logic. Stock changes
 * (`App\Actions\Catalogue\AdjustInventory`) run inside a locked transaction
 * so two concurrent admin edits (or, later, checkouts) can't both act on a
 * stale quantity.
 */
class Inventory extends Model
{
    /** @use HasFactory<InventoryFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'product_id',
        'quantity',
        'low_stock_threshold',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'low_stock_threshold' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function isOutOfStock(): bool
    {
        return $this->quantity <= 0;
    }

    public function isLowStock(): bool
    {
        return $this->quantity > 0 && $this->quantity <= $this->low_stock_threshold;
    }

    /**
     * In stock, but at or below its own threshold — out of stock (0) is its
     * own, more urgent category, so it is excluded here.
     *
     * @param  Builder<Inventory>  $query
     */
    public function scopeLowStock(Builder $query): void
    {
        $query->where('quantity', '>', 0)->whereColumn('quantity', '<=', 'low_stock_threshold');
    }

    /**
     * @param  Builder<Inventory>  $query
     */
    public function scopeOutOfStock(Builder $query): void
    {
        $query->where('quantity', '<=', 0);
    }
}
