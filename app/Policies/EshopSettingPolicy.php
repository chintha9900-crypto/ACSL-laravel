<?php

namespace App\Policies;

use App\Models\EshopSetting;
use App\Models\User;
use App\Support\Authorization\Ability;

/**
 * Delivery charge and other e-shop settings — the same permission as product prices.
 */
class EshopSettingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Ability::ManageCommerce);
    }

    public function update(User $user, EshopSetting $settings): bool
    {
        return $user->hasPermission(Ability::ManageCommerce);
    }
}
