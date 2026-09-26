<?php

namespace Tests\Feature\Notifications\Membership;

use App\Models\User;
use App\Notifications\Membership\MoreDetailsRequested;
use Illuminate\Contracts\Queue\ShouldQueue;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\MysqlTestCase;

class MoreDetailsRequestedTest extends MysqlTestCase
{
    use CreatesReviewableApplications;

    public function test_it_is_mail_only_and_not_queued(): void
    {
        $application = $this->application();
        $notification = new MoreDetailsRequested($application, 'Please send a clearer copy of your licence.');

        $this->assertSame(['mail'], $notification->via(new User));
        $this->assertNotInstanceOf(ShouldQueue::class, $notification);
    }

    public function test_the_email_carries_the_applicant_facing_message_reference_and_a_link_to_respond(): void
    {
        $this->freezeTime();
        $application = $this->application('submitted', ['full_name' => 'Nimal Perera']);
        $message = (new MoreDetailsRequested($application, 'Please send a clearer copy of your licence.'))->toMail(new User);
        $html = $message->render();

        $this->assertSame('More information needed for your Aviation Club International application', $message->subject);
        $this->assertStringContainsString('Hello Nimal Perera,', $html);
        $this->assertStringContainsString($application->public_id, $html);
        $this->assertStringContainsString('Please send a clearer copy of your licence.', $html);
        $this->assertStringContainsString(e($application->statusUrl()), $html);
        $this->assertStringContainsString("don't reply to this email", $html);
    }
}
