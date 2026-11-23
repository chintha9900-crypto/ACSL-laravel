<?php

namespace Tests\Feature;

use App\Models\JobListing;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\MysqlTestCase;

class JobListingTest extends MysqlTestCase
{
    public function test_a_job_listing_can_be_created_with_its_casts(): void
    {
        $job = JobListing::factory()->published()->create([
            'date_posted' => '2026-03-01',
            'application_deadline' => '2026-03-31',
        ])->fresh();

        $this->assertSame(JobListing::STATUS_PUBLISHED, $job->status);
        $this->assertSame('2026-03-31', $job->application_deadline->toDateString());
        $this->assertSame('2026-03-01', $job->date_posted->toDateString());
        $this->assertNotNull($job->published_at);
    }

    public function test_the_approved_categories_employment_types_and_location_types_are_defined(): void
    {
        $this->assertSame([
            'Pilots',
            'Aircraft Engineering & Maintenance',
            'Cabin Crew',
            'Flight Operations',
            'Airport Operations',
            'Ground Handling',
            'Air Traffic Management',
            'Aviation Management & Administration',
            'Aviation Training & Education',
            'Other',
        ], array_values(JobListing::CATEGORIES));
        $this->assertSame(['Full-time', 'Part-time', 'Contract', 'Temporary', 'Internship'], array_values(JobListing::EMPLOYMENT_TYPES));
        $this->assertSame(['local' => 'Local', 'overseas' => 'Overseas'], JobListing::LOCATION_TYPES);
        $this->assertSame(['draft' => 'Draft', 'published' => 'Published', 'expired' => 'Expired'], JobListing::STATUSES);
    }

    public function test_labels_are_readable(): void
    {
        $job = JobListing::factory()->overseas()->make(['category' => 'cabin_crew', 'employment_type' => 'full_time']);

        $this->assertSame('Cabin Crew', $job->categoryLabel());
        $this->assertSame('Full-time', $job->employmentTypeLabel());
        $this->assertSame('Overseas', $job->locationTypeLabel());
        $this->assertSame('Draft', $job->statusLabel());
    }

    public function test_it_belongs_to_the_user_who_created_it(): void
    {
        $user = User::factory()->create();
        $job = JobListing::factory()->create();
        $job->forceFill(['created_by_user_id' => $user->id])->save();

        $this->assertTrue($job->fresh()->createdBy->is($user));
    }

    public function test_status_and_publication_fields_are_not_mass_assignable(): void
    {
        $job = new JobListing([
            'status' => JobListing::STATUS_PUBLISHED,
            'published_at' => now(),
            'created_by_user_id' => 1,
        ]);

        $this->assertNull($job->status);
        $this->assertNull($job->published_at);
        $this->assertNull($job->created_by_user_id);
    }

    public function test_the_database_rejects_values_outside_the_approved_lists(): void
    {
        $job = JobListing::factory()->create();

        foreach (['category' => 'astronaut', 'employment_type' => 'volunteer', 'location_type' => 'remote', 'status' => 'archived'] as $column => $value) {
            try {
                DB::table('job_listings')->where('id', $job->id)->update([$column => $value]);
                $this->fail("The database accepted {$column} = {$value}.");
            } catch (QueryException) {
                $this->assertSame($job->getAttribute($column), DB::table('job_listings')->where('id', $job->id)->value($column));
            }
        }
    }

    public function test_published_jobs_with_a_future_or_todays_deadline_are_publicly_visible(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-03-10 12:00', JobListing::DEADLINE_TIMEZONE));

        $future = JobListing::factory()->published()->deadline('2026-03-20')->create();
        $today = JobListing::factory()->published()->deadline('2026-03-10')->create();

        $this->assertEqualsCanonicalizing([$future->id, $today->id], JobListing::query()->publiclyVisible()->pluck('id')->all());
        $this->assertTrue($future->fresh()->isPubliclyVisible());
        $this->assertTrue($today->fresh()->isPubliclyVisible());
    }

    public function test_past_deadline_draft_and_expired_jobs_are_not_publicly_visible(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-03-10 12:00', JobListing::DEADLINE_TIMEZONE));

        $pastButStillPublished = JobListing::factory()->published()->deadline('2026-03-09')->create();
        $draft = JobListing::factory()->deadline('2026-03-20')->create();
        $expired = JobListing::factory()->expired()->deadline('2026-03-20')->create();

        $this->assertSame([], JobListing::query()->publiclyVisible()->pluck('id')->all());

        foreach ([$pastButStillPublished, $draft, $expired] as $job) {
            $this->assertFalse($job->fresh()->isPubliclyVisible());
        }
    }

    public function test_a_job_stays_visible_until_the_end_of_its_deadline_date_in_colombo(): void
    {
        $job = JobListing::factory()->published()->deadline('2026-03-10')->create();

        // 23:59 on the deadline date in Colombo (18:29 UTC): still visible.
        $this->travelTo(CarbonImmutable::parse('2026-03-10 23:59:59', JobListing::DEADLINE_TIMEZONE));
        $this->assertSame([$job->id], JobListing::query()->publiclyVisible()->pluck('id')->all());
        $this->assertTrue($job->fresh()->isPubliclyVisible());

        // Just after midnight in Colombo — still 10 March in UTC: hidden.
        $this->travelTo(CarbonImmutable::parse('2026-03-11 00:00:01', JobListing::DEADLINE_TIMEZONE));
        $this->assertSame('2026-03-10', now('UTC')->toDateString());
        $this->assertSame([], JobListing::query()->publiclyVisible()->pluck('id')->all());
        $this->assertFalse($job->fresh()->isPubliclyVisible());
    }

    public function test_the_published_scope_uses_status_only(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-03-10 12:00', JobListing::DEADLINE_TIMEZONE));

        $pastButStillPublished = JobListing::factory()->published()->deadline('2026-03-01')->create();
        JobListing::factory()->create();
        JobListing::factory()->expired()->create();

        $this->assertSame([$pastButStillPublished->id], JobListing::query()->published()->pluck('id')->all());
    }
}
