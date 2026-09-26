<?php

namespace Tests\Concerns;

use App\Notifications\Membership\AccountSetup;
use Closure;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Reading the one-time setup link out of an AccountSetup notification, and proving the
 * plaintext token was stored nowhere.
 */
trait InspectsSetupLinks
{
    /**
     * Observe every AccountSetup notification on its way to a real (array) mailer.
     *
     * AccountSetup also has a `database` channel (A5.5), which fires its own
     * `NotificationSending` event with no setup link at all; this only observes
     * the `mail` channel, since that is the one this helper is about.
     *
     * @param  Closure(string $url): void|null  $onSending  Runs before delivery; may throw to simulate a mail failure.
     * @return object{urls: array<int, string>}
     */
    protected function captureSetupLinks(?Closure $onSending = null): object
    {
        $captured = (object) ['urls' => [], 'levels' => []];

        Event::listen(NotificationSending::class, function (NotificationSending $event) use ($captured, $onSending): void {
            if (! $event->notification instanceof AccountSetup || $event->channel !== 'mail') {
                return;
            }

            $captured->urls[] = $event->notification->setupUrl();
            $captured->levels[] = DB::transactionLevel();

            if ($onSending !== null) {
                $onSending($event->notification->setupUrl());
            }
        });

        return $captured;
    }

    protected function tokenFromUrl(string $url): string
    {
        $this->assertMatchesRegularExpression('#^https?://[^/]+/account/setup/[A-Za-z0-9]{64}$#', $url);

        return basename((string) parse_url($url, PHP_URL_PATH));
    }

    /**
     * The plaintext must not appear in any column of any table (audit, history, jobs, ...).
     */
    protected function assertTokenNotStored(string $token): void
    {
        foreach (DB::select('SHOW TABLES') as $row) {
            $table = array_values((array) $row)[0];

            $this->assertStringNotContainsString($token, DB::table($table)->get()->toJson(), "The plaintext token must not be stored in {$table}.");
        }
    }
}
