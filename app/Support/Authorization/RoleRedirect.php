<?php

namespace App\Support\Authorization;

use App\Models\Role;
use App\Models\User;

/**
 * The one place that decides where a signed-in user lands. Reads only
 * `$user->role`, loaded from the database behind the session — never from
 * request input — so login-time role-based redirection stays server-side,
 * per the approved authentication requirements.
 */
class RoleRedirect
{
    public static function homeRouteFor(User $user): string
    {
        return match ($user->role) {
            Role::ADMIN, Role::EDITOR, Role::DEV => route('admin.dashboard'),
            default => route('member.dashboard'),
        };
    }
}
