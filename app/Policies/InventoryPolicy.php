<?php

namespace App\Policies;

use App\Models\Inventory;
use App\Models\User;
use App\Support\Authorization\Ability;

class InventoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Ability::ManageCommerce);
    }

    public function update(User $user, Inventory $inventory): bool
    {
        return $user->hasPermission(Ability::ManageCommerce);
    }
}
