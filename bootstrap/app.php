<?php

use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\EnsureUserIsActive;
use App\Support\Authorization\RoleRedirect;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
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

        // Unpaid e-shop orders: PayHere deadlines are 30 minutes, so check often.
        $schedule->command('orders:expire-unpaid')->everyFiveMinutes()->withoutOverlapping();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        // PayHere's server-to-server callback cannot carry a CSRF token; it is
        // verified by its md5sig signature instead (PayHereNotificationController).
        $middleware->validateCsrfTokens(except: ['payments/payhere/notify']);

        // ...and its md5sig must be checked against the fields exactly as sent,
        // so they are not trimmed or converted for that one route.
        $isPayHereNotification = fn (Request $request): bool => $request->is('payments/payhere/notify');
        $middleware->trimStrings(except: [$isPayHereNotification]);
        $middleware->convertEmptyStringsToNull(except: [$isPayHereNotification]);

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
        // A membership application whose uploads exceed the server's POST size
        // limit is rejected by global middleware before the session starts, so
        // the applicant is sent back to the form with a flag (not flashed
        // errors) that the page turns into a clear message.
        $exceptions->render(function (PostTooLargeException $exception, Request $request) {
            if ($request->isMethod('POST') && $request->is('membership/apply')) {
                return redirect()->route('membership.apply', ['upload' => 'too-large']);
            }

            return null;
        });
    })->create();
