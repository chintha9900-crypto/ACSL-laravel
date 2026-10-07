<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;
use App\Support\Authorization\Ability;

class OrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Ability::ManageOrders);
    }

    public function view(User $user, Order $order): bool
    {
        return $user->hasPermission(Ability::ManageOrders);
    }

    public function update(User $user, Order $order): bool
    {
        return $user->hasPermission(Ability::ManageOrders);
    }
}
