<?php

namespace Tests\Feature\Notifications\Membership;

use App\Models\User;
use App\Notifications\Membership\ApplicationRejected;
use Illuminate\Contracts\Queue\ShouldQueue;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\MysqlTestCase;

class ApplicationRejectedTest extends MysqlTestCase
{
    use CreatesReviewableApplications;

    public function test_it_is_mail_only_and_not_queued(): void
    {
        $application = $this->application('rejected');
        $notification = new ApplicationRejected($application);

        $this->assertSame(['mail'], $notification->via(new User));
        $this->assertNotInstanceOf(ShouldQueue::class, $notification);
    }

    public function test_the_email_names_the_reference_and_invites_a_new_application_without_the_internal_note(): void
    {
        $application = $this->application('rejected', [
            'full_name' => 'Nimal Perera',
            'decision_note' => 'Licence photo unreadable — internal only.',
        ]);

        $html = (new ApplicationRejected($application))->toMail(new User)->render();

        $this->assertStringContainsString('Hello Nimal Perera,', $html);
        $this->assertStringContainsString($application->public_id, $html);
        $this->assertStringContainsString(route('membership.apply'), $html);
        $this->assertStringNotContainsString('Licence photo unreadable', $html, 'The internal decision note must never reach the applicant.');
    }
}
