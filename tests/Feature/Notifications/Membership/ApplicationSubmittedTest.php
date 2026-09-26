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

    /**
     * The body is fixed, approved copy — no applicant name, reference or
     * category — plus the one explicitly requested addition: the
     * application's own existing signed status link.
     */
    public function test_the_email_uses_the_approved_fixed_subject_and_body(): void
    {
        $application = $this->application('submitted', ['full_name' => 'Nimal Perera'], 'P');
        $message = (new ApplicationSubmitted($application))->toMail(new User);
        $html = $message->render();

        $this->assertSame('Application Received — Aviation Club', $message->subject);
        $this->assertStringContainsString('Dear Applicant,', $html);
        $this->assertStringContainsString('Your membership application has been received successfully. We will get back to you soon.', $html);
        $this->assertStringContainsString('You can check your application status using the secure link below:', $html);
        $this->assertStringContainsString(e($application->statusUrl()), $html);
        $this->assertStringContainsString('Aviation Club Team', $html);
        $this->assertStringContainsString('aviationclub.lk', $html);
        $this->assertStringNotContainsString('Nimal Perera', $html);
    }

    public function test_the_status_link_is_the_applications_existing_signed_url_mechanism(): void
    {
        $application = $this->application('submitted', [], 'P');

        // The email must link to exactly the same signed URL the applicant's
        // own status page already produces — no separate URL/token system.
        $this->assertStringContainsString('signature=', $application->statusUrl());

        $html = (new ApplicationSubmitted($application))->toMail(new User)->render();
        $this->assertStringContainsString(e($application->statusUrl()), $html);
    }
}
