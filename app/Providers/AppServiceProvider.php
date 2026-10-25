<?php

namespace App\Providers;

use App\Listeners\MergeGuestCartOnLogin;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The only notifiable model (docs/database/08 §2): every database
        // notification stores the short alias 'user', never the full class name.
        Relation::enforceMorphMap(['user' => User::class]);

        // RBAC foundation — the one blanket bypass in the system: an active
        // admin may do anything, independent of the `permission_role` grants
        // (which also hold every permission for `admin`, kept consistent by
        // the seed migration). Every other role is decided by its Policy via
        // `User::hasPermission()`. Returning `null` (not `false`) for a
        // non-admin lets the normal Policy/Gate checks still run.
        Gate::before(fn (User $user, string $ability) => $user->status === 'active' && $user->role === Role::ADMIN
            ? true
            : null);

        // Setup links carry a one-time token in the path, so they must never be http.
        if ($this->app->isProduction()) {
            URL::forceHttps();
        }

        // Setup emails: a few per admin per member per minute, and a hard cap per member per hour.
        RateLimiter::for('setup-link', function (Request $request) {
            $target = (string) $request->route('application');

            return [
                Limit::perMinute(3)->by('admin:'.$request->user()?->id.'|'.$target),
                Limit::perHour(10)->by('member:'.$target),
            ];
        });

        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by(Str::transliterate(
                Str::lower($request->string('email')->toString()).'|'.$request->ip()
            ));
        });

        // E-Shop Step 6: a guest's cart is merged into their own cart the
        // moment they authenticate, wherever that happens — login itself
        // never needs to know about carts.
        Event::listen(Login::class, MergeGuestCartOnLogin::class);
    }
}
