<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The approved ACI bank details shown to a member paying by bank transfer
 * (docs/database/06 §2). Never edited once a payment references it — a change
 * creates a new row and deactivates the old one, so history stays accurate.
 */
class PaymentBankAccount extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * The one active account for a currency (docs/database/06 §2: at most one,
     * enforced by the `active_currency_key` unique column).
     *
     * @param  Builder<PaymentBankAccount>  $query
     */
    public function scopeActiveForCurrency(Builder $query, string $currency): void
    {
        $query->where('currency', $currency)->where('is_active', true);
    }
}
