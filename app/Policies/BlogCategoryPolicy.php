<?php

namespace App\Policies;

use App\Models\BlogCategory;
use App\Models\User;
use App\Support\Authorization\Ability;

class BlogCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Ability::ManageContent);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Ability::ManageContent);
    }

    public function update(User $user, BlogCategory $category): bool
    {
        return $user->hasPermission(Ability::ManageContent);
    }

    public function delete(User $user, BlogCategory $category): bool
    {
        return $user->hasPermission(Ability::ManageContent);
    }
}
