<?php

namespace App\Actions\Checkout;

use App\Actions\Orders\RecordOrderAudit;
use App\Models\EshopSetting;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * An administrator deliberately sets the Sri Lanka delivery charge (zero
 * allowed only when chosen explicitly). Marks it configured, records who
 * changed it and from what. Existing orders keep their own snapshot.
 */
class UpdateDeliveryCharge
{
    public function __construct(private readonly RecordOrderAudit $audit) {}

    public function handle(User $admin, string $amount): EshopSetting
    {
        if (! Money::isValid($amount)) {
            throw new InvalidArgumentException('Not a valid delivery charge.');
        }

        return DB::transaction(function () use ($admin, $amount): EshopSetting {
            EshopSetting::current();
            $settings = EshopSetting::query()->lockForUpdate()->findOrFail(1);

            $old = [
                'delivery_charge_configured' => $settings->delivery_charge_configured,
                'delivery_charge_amount' => $settings->delivery_charge_amount,
            ];

            $settings->forceFill([
                'delivery_charge_configured' => true,
                'delivery_charge_amount' => Money::format($amount),
                'updated_by_user_id' => $admin->id,
            ])->save();

            $this->audit->handle($settings, 'eshop.delivery_charge_updated', $admin, [
                'delivery_charge_configured' => true,
                'delivery_charge_amount' => $settings->delivery_charge_amount,
            ], oldValues: $old);

            return $settings;
        }, 3);
    }
}
