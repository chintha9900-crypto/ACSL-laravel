<?php

namespace Tests\Feature\Console;

use App\Actions\Membership\StartRenewal;
use App\Models\Membership;
use App\Models\MembershipTerm;
use App\Notifications\Membership\RenewalReminder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesRenewals;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\MysqlTestCase;

class SendMembershipRenewalRemindersTest extends MysqlTestCase
{
    use CreatesRenewals;
    use CreatesReviewableApplications;

    /**
     * An active term expiring on a given date, independent of the activation
     * flow's own dates — lets each test aim precisely at one offset.
     */
    private function activeTermExpiringOn(string $expiresOn, ?Membership $membership = null): MembershipTerm
    {
        $membership ??= $this->activatedMembership();
        $term = $membership->terms->first();
        $term->forceFill([
            'starts_on' => now()->subMonths(6)->toDateString(),
            'expires_on' => $expiresOn,
        ])->save();

        return $term->fresh();
    }

    /**
     * @return array<string, array{int}>
     */
    public static function offsets(): array
    {
        return [
            '30 days' => [30],
            '14 days' => [14],
            '1 day' => [1],
        ];
    }

    #[DataProvider('offsets')]
    public function test_a_reminder_is_sent_at_the_configured_offset(int $days): void
    {
        Notification::fake();
        $term = $this->activeTermExpiringOn(now()->addDays($days)->toDateString());

        $this->artisan('membership:send-renewal-reminders')->assertSuccessful();

        Notification::assertSentTo(
            $term->membership->user,
            RenewalReminder::class,
            fn (RenewalReminder $notification, array $channels, object $notifiable): bool => str_contains(
                $notification->toDatabase($notifiable)['message'],
                "expires in {$days} ".($days === 1 ? 'day' : 'days')
            )
        );
    }

    public function test_no_reminder_is_sent_outside_the_configured_offsets(): void
    {
        Notification::fake();
        $this->activeTermExpiringOn(now()->addDays(29)->toDateString());
        $this->activeTermExpiringOn(now()->addDays(15)->toDateString());
        $this->activeTermExpiringOn(now()->addDays(2)->toDateString());

        $this->artisan('membership:send-renewal-reminders')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_running_the_command_twice_does_not_send_a_duplicate_reminder(): void
    {
        Notification::fake();
        $term = $this->activeTermExpiringOn(now()->addDays(30)->toDateString());

        $this->artisan('membership:send-renewal-reminders')->assertSuccessful();
        $this->artisan('membership:send-renewal-reminders')->assertSuccessful();

        Notification::assertSentToTimes($term->membership->user, RenewalReminder::class, 1);
        $this->assertSame(
            1,
            DB::table('membership_status_history')
                ->where('membership_term_id', $term->id)
                ->where('event', 'membership.renewal_reminder_sent')
                ->count()
        );
    }

    public function test_a_renewed_membership_does_not_receive_reminders_for_the_old_term(): void
    {
        Notification::fake();
        $membership = $this->renewableMembership();
        $this->activeTermExpiringOn(now()->addDays(30)->toDateString(), $membership);

        // A newer term already exists (e.g. the member already started renewing).
        app(StartRenewal::class)->handle($membership->fresh());

        $this->artisan('membership:send-renewal-reminders')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_the_reminder_creates_a_mail_and_database_notification(): void
    {
        $term = $this->activeTermExpiringOn(now()->addDays(14)->toDateString());

        $this->artisan('membership:send-renewal-reminders')->assertSuccessful();

        $row = DB::table('notifications')
            ->where('notifiable_id', $term->membership->user_id)
            ->where('type', RenewalReminder::class)
            ->first();

        $this->assertNotNull($row);
        $data = json_decode($row->data, true);
        $this->assertSame('Your membership expires soon', $data['title']);
        $this->assertSame(route('member.membership.show'), $data['action_url']);
    }
}
