<?php

namespace App\Providers;

use App\Listeners\MergeGuestCartOnLogin;
use App\Models\User;
use App\Payments\ManualPaymentGateway;
use App\Payments\PaymentGatewayContract;
use App\Payments\UnavailablePaymentGateway;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
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
        // E-Shop Step 7 — the one line a real gateway integration changes:
        // bind a different `PaymentGatewayContract` implementation here and
        // nothing in PaymentController or the checkout/order flow needs to
        // change.
        //
        // E-Shop Step 10.1 — `ManualPaymentGateway` is a development-only
        // simulator with no real payment check behind it, so it is bound
        // only in `local`/`testing`. Every other environment (production,
        // staging, …) binds `UnavailablePaymentGateway` instead, which fails
        // every confirm/fail/cancel with a 404 rather than letting anyone
        // self-confirm a payment until a real gateway replaces both.
        //
        // Read from `config('app.env')` rather than `$app->environment()`:
        // both reflect the same `APP_ENV` value, but the config entry can be
        // overridden in a test (`config(['app.env' => 'production'])`)
        // without also flipping `$app->runningUnitTests()` — which the
        // framework's own CSRF bypass depends on — the way replacing the
        // container's `'env'` binding would.
        $this->app->bind(PaymentGatewayContract::class, fn ($app) => in_array($app['config']->get('app.env'), ['local', 'testing'], true)
            ? $app->make(ManualPaymentGateway::class)
            : $app->make(UnavailablePaymentGateway::class));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The only notifiable model (docs/database/08 §2): every database
        // notification stores the short alias 'user', never the full class name.
        Relation::enforceMorphMap(['user' => User::class]);

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
