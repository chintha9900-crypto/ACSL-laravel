<?php

namespace Tests\Feature\Notifications\Membership;

use App\Models\User;
use App\Notifications\Membership\AccountSetup;
use App\Providers\AppServiceProvider;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\InspectsSetupLinks;
use Tests\MysqlTestCase;

class AccountSetupTest extends MysqlTestCase
{
    use InspectsSetupLinks;

    private const TOKEN = 'AbCdEfGhIjKlMnOpQrStUvWxYz0123456789AbCdEfGhIjKlMnOpQrStUvWxYz01';

    private function notification(): AccountSetup
    {
        return new AccountSetup(self::TOKEN, Carbon::parse('2026-09-24 12:00:00', 'UTC'));
    }

    public function test_it_uses_the_mail_and_database_channels_and_is_not_queued(): void
    {
        $notification = $this->notification();

        $this->assertSame(['mail', 'database'], $notification->via(new User));
        $this->assertNotInstanceOf(ShouldQueue::class, $notification, 'The token must never be serialised into the jobs table.');
    }

    public function test_the_database_row_carries_no_link_or_secret(): void
    {
        $data = $this->notification()->toDatabase(new User);

        $this->assertSame('Set up your account', $data['title']);
        $this->assertStringContainsString('Check your email', $data['message']);
        $this->assertNull($data['action_url'], 'The one-time token must never reach the database channel.');
        $this->assertNull($data['related_public_id']);
        $this->assertStringNotContainsString(self::TOKEN, json_encode($data));
    }

    public function test_the_email_names_the_club_and_member_and_explains_the_one_time_link(): void
    {
        $member = User::factory()->make(['name' => 'Nimal Perera']);
        $message = $this->notification()->toMail($member);
        $html = $message->render();

        $this->assertSame('Set up your Aviation Club International account', $message->subject);
        $this->assertStringContainsString('Aviation Club International', $html);
        $this->assertStringContainsString('Hello Nimal Perera,', $html);
        $this->assertStringContainsString('once only', $html);
        $this->assertStringContainsString('create your password', $html);
        // 12:00 UTC is 17:30 in Colombo.
        $this->assertStringContainsString('24 September 2026, 17:30 (Asia/Colombo)', $html);
        $this->assertStringContainsString('/account/setup/'.self::TOKEN, $html);
    }

    public function test_the_token_appears_only_inside_the_setup_url(): void
    {
        $member = User::factory()->make(['name' => 'Nimal Perera']);
        $html = $this->notification()->toMail($member)->render();
        $text = view('emails.membership.account-setup-text', [
            'name' => 'Nimal Perera',
            'url' => $this->notification()->setupUrl(),
            'expiresAt' => '24 September 2026, 17:30 (Asia/Colombo)',
        ])->render();

        foreach ([$html, $text] as $body) {
            $this->assertGreaterThan(0, substr_count($body, self::TOKEN));
            $this->assertSame(substr_count($body, self::TOKEN), substr_count($body, '/account/setup/'.self::TOKEN));
        }

        $this->assertStringNotContainsString('membership number', strtolower($html));
        $this->assertStringContainsString('once only', $text);
    }

    public function test_the_members_name_is_escaped(): void
    {
        $member = User::factory()->make(['name' => '<script>alert(1)</script> [click](https://evil.test)']);

        $html = $this->notification()->toMail($member)->render();

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_the_url_points_at_the_existing_account_setup_route(): void
    {
        $this->assertSame(route('account.setup', ['token' => self::TOKEN]), $this->notification()->setupUrl());
        $this->assertSame(self::TOKEN, $this->tokenFromUrl($this->notification()->setupUrl()));
    }

    public function test_links_are_https_in_production(): void
    {
        $this->app['env'] = 'production';
        (new AppServiceProvider($this->app))->boot();

        $this->assertStringStartsWith('https://', $this->notification()->setupUrl());
        URL::forceScheme(null);
    }

    public function test_it_is_delivered_as_an_html_and_text_email_to_the_member_only(): void
    {
        $member = User::factory()->create(['name' => 'Nimal Perera', 'email' => 'nimal@example.test']);

        $member->notify($this->notification());

        $this->assertSame('array', config('mail.default'), 'Tests never reach a real mail server.');
        $sent = Mail::mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $sent);

        $email = $sent->first()->getOriginalMessage();
        $this->assertCount(1, $email->getTo());
        $this->assertSame('nimal@example.test', $email->getTo()[0]->getAddress());
        $this->assertEmpty($email->getCc());
        $this->assertEmpty($email->getBcc());
        $this->assertStringContainsString('/account/setup/'.self::TOKEN, $email->getHtmlBody());
        $this->assertStringContainsString('/account/setup/'.self::TOKEN, $email->getTextBody());
    }
}
