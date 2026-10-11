<?php

namespace Tests\Feature\Notifications\Membership;

use App\Actions\Membership\ActivateMembership;
use App\Notifications\Membership\Welcome;
use Illuminate\Contracts\Queue\ShouldQueue;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\MysqlTestCase;

class WelcomeTest extends MysqlTestCase
{
    use CreatesReviewableApplications;

    public function test_it_uses_mail_and_database_and_is_not_queued(): void
    {
        $application = $this->application('approved', ['full_name' => 'Nimal Perera']);
        $activated = app(ActivateMembership::class)->handle($application, $this->admin());
        $notification = new Welcome($activated->membership);

        $this->assertSame(['mail', 'database'], $notification->via($activated->membership->user));
        $this->assertNotInstanceOf(ShouldQueue::class, $notification);
    }

    public function test_the_email_names_the_member_number_category_and_introductory_dates(): void
    {
        $application = $this->application('approved', ['full_name' => 'Nimal Perera'], 'P');
        $activated = app(ActivateMembership::class)->handle($application, $this->admin());
        $membership = $activated->membership->fresh(['category', 'terms', 'user']);

        $message = (new Welcome($membership))->toMail($membership->user);
        $html = $message->render();

        $this->assertSame('Welcome to Aviation Club International', $message->subject);
        $this->assertStringContainsString('Hello Nimal Perera,', $html);
        $this->assertStringContainsString($membership->membership_number, $html);
        $this->assertStringContainsString($membership->category->name, $html);
        $this->assertStringContainsString($membership->terms->first()->expires_on->format('j F Y'), $html);
    }

    public function test_both_versions_congratulate_and_explain_how_to_sign_in_with_the_registered_email(): void
    {
        $application = $this->application('approved', ['full_name' => 'Nimal Perera', 'email' => 'nimal.member@example.test'], 'S');
        $activated = app(ActivateMembership::class)->handle($application, $this->admin());
        $membership = $activated->membership->fresh(['category', 'terms', 'user']);

        $message = (new Welcome($membership))->toMail($membership->user);
        $html = $message->render();
        $text = view('emails.membership.welcome-text', $message->viewData)->render();
        $loginUrl = route('login');

        $this->assertStringStartsWith('http', $loginUrl);
        $this->assertSame(url('/login'), $loginUrl);
        $this->assertSame($loginUrl, $message->viewData['loginUrl']);
        $this->assertSame('nimal.member@example.test', $message->viewData['email']);

        foreach ([$html, $text] as $body) {
            $this->assertStringContainsString('Congratulations, and welcome to Aviation Club International! You are now a member', $body);
            $this->assertStringContainsString('Membership number: ', $body);
            $this->assertStringContainsString($membership->membership_number, $body);
            $this->assertStringContainsString($loginUrl, $body);
            $this->assertStringContainsString('using your registered email address', $body);
            $this->assertStringContainsString('nimal.member@example.test', $body);
            $this->assertStringContainsString('as your username', $body);
            $this->assertStringContainsString('secure, one-time link to create your account password', $body);
        }

        $this->assertStringContainsString('href="'.$loginUrl.'"', $html);
    }

    public function test_the_welcome_email_never_contains_a_password_or_the_setup_token(): void
    {
        $application = $this->application('approved', ['full_name' => 'Nimal Perera']);
        $activated = app(ActivateMembership::class)->handle($application, $this->admin());
        $membership = $activated->membership->fresh(['category', 'terms', 'user']);

        $message = (new Welcome($membership))->toMail($membership->user);
        $html = $message->render();
        $text = view('emails.membership.welcome-text', $message->viewData)->render();

        $this->assertNull($membership->user->password);
        foreach ([$html, $text] as $body) {
            $this->assertStringNotContainsString($activated->setupToken, $body);
            $this->assertStringNotContainsString('/account/setup/', $body);
            $this->assertDoesNotMatchRegularExpression('/(temporary|temp)\s+password|password\s*:/i', $body);
        }
        $this->assertArrayNotHasKey('password', $message->viewData);
    }

    public function test_the_database_row_has_a_safe_summary_and_a_link_to_the_membership_page(): void
    {
        $application = $this->application('approved');
        $activated = app(ActivateMembership::class)->handle($application, $this->admin());
        $membership = $activated->membership;

        $data = (new Welcome($membership))->toDatabase($membership->user);

        $this->assertStringContainsString($membership->membership_number, $data['message']);
        $this->assertSame(route('member.membership.show'), $data['action_url']);
        $this->assertNull($data['related_public_id']);
    }
}
