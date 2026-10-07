<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Coarse, route-level defence in depth for the admin area: a signed-in user
 * whose role isn't in the allowed list never reaches any admin controller at
 * all, rather than relying solely on each action's own Policy check. This
 * does not replace those Policy checks — a role allowed past this
 * middleware (e.g. `editor`, `dev`) can still be denied a specific action by
 * its Policy, which is where the real, fine-grained permission check lives.
 *
 * Runs after `auth`/`active`, so `$request->user()` is always a signed-in,
 * active account here — anything else (missing, unrecognised, or not in the
 * allowed list) fails closed with a 403.
 */
class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        abort_if($user === null || ! in_array($user->role, $roles, true), 403);

        return $next($request);
    }
}
