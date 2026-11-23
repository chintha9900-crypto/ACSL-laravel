<?php

namespace Tests\Feature\Console;

use App\Models\JobListing;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Tests\MysqlTestCase;

class ExpireJobListingsTest extends MysqlTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-03-10 12:00', JobListing::DEADLINE_TIMEZONE));
    }

    public function test_published_jobs_past_their_deadline_become_expired(): void
    {
        $job = JobListing::factory()->published()->deadline('2026-03-09')->create();
        $publishedAt = $job->fresh()->published_at;

        $this->artisan('jobs:expire')->expectsOutput('Expired 1 job listing(s).')->assertSuccessful();

        $fresh = $job->fresh();
        $this->assertSame(JobListing::STATUS_EXPIRED, $fresh->status);
        $this->assertEquals($publishedAt, $fresh->published_at);
    }

    public function test_jobs_with_todays_or_a_future_deadline_stay_published(): void
    {
        $today = JobListing::factory()->published()->deadline('2026-03-10')->create();
        $future = JobListing::factory()->published()->deadline('2026-04-01')->create();

        $this->artisan('jobs:expire')->assertSuccessful();

        $this->assertSame(JobListing::STATUS_PUBLISHED, $today->fresh()->status);
        $this->assertSame(JobListing::STATUS_PUBLISHED, $future->fresh()->status);
    }

    public function test_draft_and_already_expired_jobs_are_untouched(): void
    {
        $draft = JobListing::factory()->deadline('2026-03-01')->create();
        $expired = JobListing::factory()->expired()->deadline('2026-03-01')->create();
        $expiredUpdatedAt = $expired->fresh()->updated_at;

        $this->travel(1)->hours();
        $this->artisan('jobs:expire')->expectsOutput('Expired 0 job listing(s).')->assertSuccessful();

        $this->assertSame(JobListing::STATUS_DRAFT, $draft->fresh()->status);
        $this->assertSame(JobListing::STATUS_EXPIRED, $expired->fresh()->status);
        $this->assertEquals($expiredUpdatedAt, $expired->fresh()->updated_at);
    }

    public function test_the_deadline_is_judged_in_colombo_not_utc(): void
    {
        $job = JobListing::factory()->published()->deadline('2026-03-10')->create();

        // 23:30 Colombo on the deadline date: not yet expired.
        $this->travelTo(CarbonImmutable::parse('2026-03-10 23:30', JobListing::DEADLINE_TIMEZONE));
        $this->artisan('jobs:expire')->assertSuccessful();
        $this->assertSame(JobListing::STATUS_PUBLISHED, $job->fresh()->status);

        // 00:30 Colombo the next day, while UTC is still 10 March: expired.
        $this->travelTo(CarbonImmutable::parse('2026-03-11 00:30', JobListing::DEADLINE_TIMEZONE));
        $this->assertSame('2026-03-10', now('UTC')->toDateString());
        $this->artisan('jobs:expire')->assertSuccessful();
        $this->assertSame(JobListing::STATUS_EXPIRED, $job->fresh()->status);
    }

    public function test_running_the_command_twice_gives_the_same_result_and_deletes_nothing(): void
    {
        $past = JobListing::factory()->published()->deadline('2026-03-01')->create();
        $live = JobListing::factory()->published()->deadline('2026-03-20')->create();
        $draft = JobListing::factory()->deadline('2026-03-01')->create();

        $this->artisan('jobs:expire')->expectsOutput('Expired 1 job listing(s).')->assertSuccessful();
        $afterFirst = JobListing::query()->orderBy('id')->pluck('status', 'id')->all();

        $this->artisan('jobs:expire')->expectsOutput('Expired 0 job listing(s).')->assertSuccessful();

        $this->assertSame($afterFirst, JobListing::query()->orderBy('id')->pluck('status', 'id')->all());
        $this->assertSame([
            $past->id => JobListing::STATUS_EXPIRED,
            $live->id => JobListing::STATUS_PUBLISHED,
            $draft->id => JobListing::STATUS_DRAFT,
        ], $afterFirst);
        $this->assertSame(3, JobListing::query()->count());
    }

    public function test_the_command_is_scheduled_hourly_without_overlapping(): void
    {
        // The withSchedule() callback runs when a schedule command boots it.
        $this->artisan('schedule:list')->assertSuccessful();

        $events = collect($this->app->make(Schedule::class)->events());
        $find = fn (string $name) => $events->first(fn ($event) => str_contains((string) $event->command, $name));

        $event = $find('jobs:expire');
        $this->assertNotNull($event, 'jobs:expire must be registered with the scheduler.');
        $this->assertSame('0 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);

        // The existing tasks keep their original frequencies.
        $this->assertSame('0 0 * * *', $find('membership:expire-terms')?->expression);
        $this->assertSame('0 0 * * *', $find('membership:send-renewal-reminders')?->expression);
        $this->assertSame('*/5 * * * *', $find('orders:expire-unpaid')?->expression);
    }
}
