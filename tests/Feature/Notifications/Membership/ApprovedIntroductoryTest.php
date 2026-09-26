<?php

namespace Tests\Feature\Notifications\Membership;

use App\Models\MembershipSetting;
use App\Models\User;
use App\Notifications\Membership\ApprovedIntroductory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\MysqlTestCase;

class ApprovedIntroductoryTest extends MysqlTestCase
{
    use CreatesReviewableApplications;

    public function test_it_is_mail_only_and_not_queued(): void
    {
        $application = $this->application('approved');
        $notification = new ApprovedIntroductory($application);

        $this->assertSame(['mail'], $notification->via(new User));
        $this->assertNotInstanceOf(ShouldQueue::class, $notification);
    }

    public function test_the_email_confirms_approval_and_the_free_period_from_settings_not_hard_coded(): void
    {
        MembershipSetting::current();
        DB::table('membership_settings')->where('id', 1)->update(['introductory_period_months' => 9]);

        $application = $this->application('approved', ['full_name' => 'Nimal Perera'], 'P');
        $message = (new ApprovedIntroductory($application))->toMail(new User);
        $html = $message->render();

        $this->assertSame('Your Aviation Club International application has been approved', $message->subject);
        $this->assertStringContainsString('Hello Nimal Perera,', $html);
        $this->assertStringContainsString($application->category->name, $html);
        $this->assertStringContainsString('first 9 months', $html);
        $this->assertStringNotContainsString('6 months', $html, 'The free period must come from settings, never a hard-coded default.');
        $this->assertStringNotContainsString('$', $html, 'The introductory period is free — no price is ever shown.');
    }
}
