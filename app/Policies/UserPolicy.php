<?php

namespace App\Policies;

use App\Models\User;
use App\Support\Authorization\Ability;

/**
 * A user account can only ever be edited by the account it belongs to (no
 * "admin edits a member's profile" feature exists, or is implied by this).
 */
class UserPolicy
{
    public function update(User $user, User $target): bool
    {
        return $user->id === $target->id;
    }

    /**
     * Inviting a staff account (editor) is user administration: admin only,
     * through `users.manage`, which no other role holds.
     */
    public function invite(User $user): bool
    {
        return $user->hasPermission(Ability::ManageUsers);
    }
}
