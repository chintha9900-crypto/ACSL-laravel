<?php

namespace Tests\Feature\Console;

use App\Actions\Membership\StartRenewal;
use App\Models\Membership;
use App\Models\MembershipTerm;
use App\Notifications\Membership\RenewalReminder;
use Illuminate\Support\Carbon;
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
     * "Today" as the command itself sees it (`SendMembershipRenewalReminders`
     * anchors its offset math on `config('membership.business_timezone')`,
     * not the app's default UTC) — expiry fixtures below must be built the
     * same way, or a date built from UTC `now()` can land on the wrong
     * calendar day whenever UTC and the business timezone currently disagree.
     */
    private function businessToday(): Carbon
    {
        return Carbon::now(config('membership.business_timezone'))->startOfDay();
    }

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
        $term = $this->activeTermExpiringOn($this->businessToday()->addDays($days)->toDateString());

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

    /**
     * The command anchors its offset math on the business timezone
     * (`SendMembershipRenewalReminders::handle()`), not the app's default
     * UTC. Travelling to a UTC instant where Colombo has already rolled to
     * the next calendar date proves the offset is still measured from the
     * business date, not from a UTC "today" that disagrees with it.
     */
    public function test_the_offset_is_measured_from_the_business_date_not_utc(): void
    {
        Notification::fake();
        // 20:00 UTC on 26 Sep is already 01:30 on 27 Sep in Colombo.
        $this->travelTo(Carbon::parse('2026-09-26 20:00:00', 'UTC'));
        $this->assertSame('2026-09-27', $this->businessToday()->toDateString());

        $term = $this->activeTermExpiringOn($this->businessToday()->addDays(14)->toDateString());
        $this->assertSame('2026-10-11', $term->expires_on->toDateString());

        $this->artisan('membership:send-renewal-reminders')->assertSuccessful();

        Notification::assertSentTo($term->membership->user, RenewalReminder::class);
    }

    public function test_no_reminder_is_sent_outside_the_configured_offsets(): void
    {
        Notification::fake();
        $this->activeTermExpiringOn($this->businessToday()->addDays(29)->toDateString());
        $this->activeTermExpiringOn($this->businessToday()->addDays(15)->toDateString());
        $this->activeTermExpiringOn($this->businessToday()->addDays(2)->toDateString());

        $this->artisan('membership:send-renewal-reminders')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_running_the_command_twice_does_not_send_a_duplicate_reminder(): void
    {
        Notification::fake();
        $term = $this->activeTermExpiringOn($this->businessToday()->addDays(30)->toDateString());

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
        $this->activeTermExpiringOn($this->businessToday()->addDays(30)->toDateString(), $membership);

        // A newer term already exists (e.g. the member already started renewing).
        app(StartRenewal::class)->handle($membership->fresh());

        $this->artisan('membership:send-renewal-reminders')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_the_reminder_creates_a_mail_and_database_notification(): void
    {
        $term = $this->activeTermExpiringOn($this->businessToday()->addDays(14)->toDateString());

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
