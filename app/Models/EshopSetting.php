<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Typed singleton of e-shop settings. The Sri Lanka delivery charge is
 * usable only once `delivery_charge_configured` is true — an unset charge
 * never means free delivery.
 */
class EshopSetting extends Model
{
    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'delivery_charge_configured' => 'boolean',
            'delivery_charge_amount' => 'decimal:2',
        ];
    }

    /**
     * The singleton row, created unconfigured on first use.
     */
    public static function current(): static
    {
        static::query()->insertOrIgnore([
            'id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return static::query()->findOrFail(1);
    }

    /**
     * The configured delivery charge as a decimal string, or null when an
     * administrator has not set one yet.
     */
    public static function deliveryCharge(): ?string
    {
        $settings = static::query()->find(1);

        return $settings?->delivery_charge_configured ? $settings->delivery_charge_amount : null;
    }
}
