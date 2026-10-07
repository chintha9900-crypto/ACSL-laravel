<?php

use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\EnsureUserIsActive;
use App\Support\Authorization\RoleRedirect;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        // M11 then M10: close out terms that have already lapsed before
        // reminding members about ones still approaching expiry.
        $schedule->command('membership:expire-terms')->daily();
        $schedule->command('membership:send-renewal-reminders')->daily();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'role' => EnsureUserHasRole::class,
        ]);

        // Laravel's "guest" middleware auto-redirects an already-authenticated visitor
        // to any GET route whose path is literally "dashboard" (Auth\Middleware\
        // RedirectIfAuthenticated::defaultRedirectUri()). The member dashboard lives at
        // that path, so this pins the existing redirect explicitly rather than letting
        // that pick a route itself — and, per the approved RBAC/authentication design,
        // sends an already-authenticated visitor who revisits /login to their own
        // role's landing area (admin/editor/dev -> the admin dashboard, member -> the
        // member dashboard) rather than always to the public homepage.
        $middleware->redirectUsersTo(fn (Request $request) => RoleRedirect::homeRouteFor($request->user()));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
