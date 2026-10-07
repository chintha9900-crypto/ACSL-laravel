<?php

namespace App\Actions\Contact;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Server-side verification of a Google reCAPTCHA v2 checkbox token. The
 * client-side widget is never trusted on its own.
 *
 * Environment behaviour:
 * - no secret key, and running in `local` or `testing`: verification is skipped
 * - no secret key anywhere else (including production and staging): fail closed
 * - secret key present: the site key and expected hostname must also be
 *   configured, otherwise the widget cannot be shown or the response cannot be
 *   checked, so the submission is rejected before any token is sent
 * - secret key present: the token must be verified by Google, within a short timeout
 *
 * When Google returns a hostname, it must match the configured expected hostname
 * (case-insensitive). A network failure or timeout while calling Google is
 * treated as a failed verification (fail closed), with no visitor data logged.
 */
class VerifyRecaptchaToken
{
    private const VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

    private const TIMEOUT_SECONDS = 5;

    private const SKIPPABLE_ENVIRONMENTS = ['local', 'testing'];

    public function handle(?string $token, ?string $remoteIp): bool
    {
        $secret = config('services.recaptcha.secret_key');

        if (blank($secret)) {
            return in_array(config('app.env'), self::SKIPPABLE_ENVIRONMENTS, true);
        }

        if (blank(config('services.recaptcha.site_key')) || blank(config('services.recaptcha.hostname'))) {
            return false;
        }

        if (blank($token)) {
            return false;
        }

        try {
            $response = Http::asForm()
                ->timeout(self::TIMEOUT_SECONDS)
                ->post(self::VERIFY_URL, array_filter([
                    'secret' => $secret,
                    'response' => $token,
                    'remoteip' => $remoteIp,
                ]));
        } catch (ConnectionException $exception) {
            Log::warning('reCAPTCHA verification could not be completed.', [
                'exception' => $exception::class,
            ]);

            return false;
        }

        if (! $response->successful() || $response->json('success') !== true) {
            return false;
        }

        $returnedHostname = $response->json('hostname');

        if ($returnedHostname === null) {
            return true;
        }

        return is_string($returnedHostname)
            && strcasecmp($returnedHostname, config('services.recaptcha.hostname')) === 0;
    }
}
