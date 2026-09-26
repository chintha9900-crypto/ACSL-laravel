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
