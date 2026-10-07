<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;
use App\Support\Authorization\Ability;

class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Ability::ManageCommerce);
    }

    public function view(User $user, Product $product): bool
    {
        return $user->hasPermission(Ability::ManageCommerce);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Ability::ManageCommerce);
    }

    public function update(User $user, Product $product): bool
    {
        return $user->hasPermission(Ability::ManageCommerce);
    }

    public function delete(User $user, Product $product): bool
    {
        return $user->hasPermission(Ability::ManageCommerce);
    }
}
