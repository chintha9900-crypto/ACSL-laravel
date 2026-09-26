<?php

namespace App\Policies;

use App\Models\User;

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
}
