<?php

namespace App\Policies;

use App\Models\ProductCategory;
use App\Models\User;
use App\Support\Authorization\Ability;

class ProductCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Ability::ManageCommerce);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Ability::ManageCommerce);
    }

    public function update(User $user, ProductCategory $category): bool
    {
        return $user->hasPermission(Ability::ManageCommerce);
    }

    public function delete(User $user, ProductCategory $category): bool
    {
        return $user->hasPermission(Ability::ManageCommerce);
    }
}
