<?php

namespace App\Payments;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Decides whether the "Dummy / Test Payment — Development Only" method may be
 * offered and used for a request. Every condition must hold:
 *
 * - PAYMENT_DUMMY_ENABLED is true (off by default);
 * - APP_ENV is not `production` (always refused there, whatever else is set);
 * - neither the request host nor the APP_URL host is a production host
 *   (hard-coded below, so no environment value can re-enable it there);
 * - the request host is in PAYMENT_DUMMY_ALLOWED_HOSTS;
 * - the visitor is signed in with an email in PAYMENT_DUMMY_ALLOWED_EMAILS.
 */
class DummyPaymentGate
{
    /**
     * @var list<string>
     */
    public const PRODUCTION_HOSTS = ['aviationclub.lk', 'www.aviationclub.lk'];

    public function allows(Request $request): bool
    {
        if (config('payments.dummy.enabled') !== true || config('app.env') === 'production') {
            return false;
        }

        $host = strtolower($request->getHost());
        $appHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

        if (in_array($host, self::PRODUCTION_HOSTS, true) || in_array($appHost, self::PRODUCTION_HOSTS, true)) {
            return false;
        }

        $allowedHosts = array_map('strtolower', (array) config('payments.dummy.allowed_hosts'));

        if (! in_array($host, $allowedHosts, true)) {
            return false;
        }

        return $this->isTestAccount($request->user());
    }

    private function isTestAccount(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        $allowedEmails = array_map('strtolower', (array) config('payments.dummy.allowed_emails'));

        return in_array(strtolower($user->email), $allowedEmails, true);
    }
}
