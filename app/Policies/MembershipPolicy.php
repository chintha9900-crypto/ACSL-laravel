<?php

namespace App\Policies;

use App\Models\Membership;
use App\Models\User;

/**
 * A membership is visible only to the member it belongs to (docs/frontend/01 §"Member").
 * The dashboard resolves the membership from the logged-in user's id, never from a
 * URL parameter, but this policy is the enforced backstop against ownership mistakes.
 */
class MembershipPolicy
{
    public function view(User $user, Membership $membership): bool
    {
        return $user->role === 'member' && $membership->user_id === $user->id;
    }
}
