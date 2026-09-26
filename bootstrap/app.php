<?php

use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'active' => EnsureUserIsActive::class,
        ]);

        // Laravel's "guest" middleware auto-redirects an already-authenticated visitor
        // to any GET route whose path is literally "dashboard" (Auth\Middleware\
        // RedirectIfAuthenticated::defaultRedirectUri()). The member dashboard lives at
        // that path, so this pins the existing "/" redirect explicitly rather than
        // letting that add the member dashboard route silently change it.
        $middleware->redirectUsersTo('/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
