<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The paid renewal terms for a category (docs/database/04 §4). The introductory
 * period is not a plan — it has no fee. Fee/currency/duration are data ACI
 * supplies (OD-01, OD-02); nothing here is hard-coded or seeded by the app.
 */
class MembershipPlan extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fee_amount' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<MembershipCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(MembershipCategory::class, 'membership_category_id');
    }

    /**
     * The one active plan for a category (docs/database/04 §4: at most one,
     * enforced by the `active_category_key` unique column).
     *
     * @param  Builder<MembershipPlan>  $query
     */
    public function scopeActiveForCategory(Builder $query, int $categoryId): void
    {
        $query->where('membership_category_id', $categoryId)->where('is_active', true);
    }
}
