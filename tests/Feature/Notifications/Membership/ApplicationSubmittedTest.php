<?php

namespace Tests\Feature\Notifications\Membership;

use App\Models\User;
use App\Notifications\Membership\ApplicationSubmitted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\MysqlTestCase;

class ApplicationSubmittedTest extends MysqlTestCase
{
    use CreatesReviewableApplications;

    public function test_it_is_mail_only_and_not_queued(): void
    {
        $application = $this->application('submitted', ['full_name' => 'Nimal Perera'], 'P');
        $notification = new ApplicationSubmitted($application);

        $this->assertSame(['mail'], $notification->via(new User));
        $this->assertNotInstanceOf(ShouldQueue::class, $notification);
    }

    public function test_the_email_names_the_applicant_reference_category_and_status_link(): void
    {
        $this->freezeTime();
        $application = $this->application('submitted', ['full_name' => 'Nimal Perera'], 'P');
        $message = (new ApplicationSubmitted($application))->toMail(new User);
        $html = $message->render();

        $this->assertSame("We've received your Aviation Club International application", $message->subject);
        $this->assertStringContainsString('Hello Nimal Perera,', $html);
        $this->assertStringContainsString($application->public_id, $html);
        $this->assertStringContainsString($application->category->name, $html);
        $this->assertStringContainsString(e($application->statusUrl()), $html);
    }
}
