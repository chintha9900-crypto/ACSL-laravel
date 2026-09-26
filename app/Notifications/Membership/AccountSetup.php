<?php

namespace App\Notifications\Membership;

use Carbon\CarbonInterface;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use SensitiveParameter;

/**
 * The one-time "create your password" link for a newly activated member.
 *
 * Deliberately NOT queued: it is sent synchronously so the plaintext token is never
 * serialised into the jobs / failed_jobs tables. The token appears only in the URL.
 */
class AccountSetup extends Notification
{
    public function __construct(
        #[SensitiveParameter] private readonly string $token,
        private readonly CarbonInterface $expiresAt,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function setupUrl(): string
    {
        return route('account.setup', ['token' => $this->token]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $timezone = (string) config('membership.business_timezone');

        return (new MailMessage)
            ->subject('Set up your Aviation Club International account')
            ->view(
                ['emails.membership.account-setup', 'emails.membership.account-setup-text'],
                [
                    'name' => $notifiable->name,
                    'url' => $this->setupUrl(),
                    'expiresAt' => $this->expiresAt->copy()->setTimezone($timezone)->format('j F Y, H:i').' ('.$timezone.')',
                ],
            );
    }

    /**
     * Keep the token out of dumps of the notification object.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['id' => $this->id ?? null, 'token' => '[redacted]'];
    }
}
