<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\JobListing;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\Concerns\CreatesUploads;
use Tests\MysqlTestCase;

/**
 * Admin and Editor must have exactly the same Jobs management access: every
 * action is run, end to end, once as each role, and the recorded outcomes
 * (HTTP status, resulting job state, logo files, visible actions) must be
 * identical. Admins pass via `Gate::before`; editors via their
 * `content.manage`/`content.publish` grants — this proves the two paths
 * give the same result.
 */
class JobListingRoleEquivalenceTest extends MysqlTestCase
{
    use CreatesReviewableApplications;
    use CreatesUploads;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->travelTo(CarbonImmutable::parse('2026-03-10 12:00', JobListing::DEADLINE_TIMEZONE));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'title' => 'Aircraft Maintenance Engineer',
            'company_name' => 'Example MRO',
            'category' => 'aircraft_engineering_maintenance',
            'employment_type' => 'full_time',
            'location_type' => 'overseas',
            'location' => 'Dubai',
            'country' => 'United Arab Emirates',
            'short_description' => 'B1 licensed engineer.',
            'description' => '<p>Full description.</p>',
            'application_deadline' => '2026-03-31',
            'application_url' => 'https://example.com/careers/b1',
            ...$overrides,
        ];
    }

    /**
     * Runs every Jobs management action as `$user` and records what happened.
     *
     * @return array<string, mixed>
     */
    private function exerciseEveryAction(User $user): array
    {
        $outcome = [];
        $disk = Storage::disk('public');
        $this->actingAs($user);

        // Dashboard entry points and every status filter.
        $dashboard = $user->role === 'admin' ? route('admin.dashboard') : route('editor.dashboard');
        $outcome['dashboard shows Jobs link'] = str_contains($this->get($dashboard)->getContent(), 'href="'.route('admin.jobs.index').'"');
        foreach (['', 'draft', 'published', 'expired'] as $status) {
            $outcome["index ?status={$status}"] = $this->get(route('admin.jobs.index', array_filter(['status' => $status])))->status();
        }
        $outcome['create page'] = $this->get(route('admin.jobs.create'))->status();

        // Create a draft with a logo, then view and edit it.
        $outcome['store'] = $this->post(route('admin.jobs.store'), $this->payload(['company_logo' => $this->pngUpload()]))->status();
        $job = JobListing::query()->latest('id')->firstOrFail();
        $firstLogo = $job->company_logo;
        $outcome['stored as draft'] = $job->status;
        $outcome['logo uploaded'] = $firstLogo !== null && $disk->exists($firstLogo);
        $outcome['show page'] = $this->get(route('admin.jobs.show', $job))->status();
        $outcome['edit page'] = $this->get(route('admin.jobs.edit', $job))->status();
        $outcome['index actions for draft'] = $this->visibleActions($job);

        // Save a draft without a URL, then replace the logo.
        $outcome['update draft without url'] = $this->patch(route('admin.jobs.update', $job), $this->payload(['application_url' => '']))->status();
        $outcome['still draft after save'] = $job->fresh()->status;
        $outcome['replace logo'] = $this->patch(route('admin.jobs.update', $job), $this->payload(['company_logo' => $this->pngUpload('new.png')]))->status();
        $secondLogo = $job->fresh()->company_logo;
        $outcome['old logo removed'] = ! $disk->exists($firstLogo);
        $outcome['new logo stored'] = $secondLogo !== $firstLogo && $disk->exists($secondLogo);

        // Publish, unpublish, publish again.
        $outcome['publish'] = $this->post(route('admin.jobs.publish', $job))->status();
        $outcome['status after publish'] = $job->fresh()->status;
        $outcome['index actions for published'] = $this->visibleActions($job->fresh());
        $outcome['unpublish'] = $this->post(route('admin.jobs.unpublish', $job))->status();
        $outcome['status after unpublish'] = $job->fresh()->status;

        // Expire, extend the deadline (stays expired), then republish.
        $job->forceFill(['status' => JobListing::STATUS_PUBLISHED, 'application_deadline' => '2026-03-09'])->save();
        $this->artisan('jobs:expire')->assertSuccessful();
        $outcome['status after expiry'] = $job->fresh()->status;
        $outcome['expired listed'] = str_contains($this->get(route('admin.jobs.index', ['status' => 'expired']))->getContent(), e($job->title));
        $outcome['index actions for expired'] = $this->visibleActions($job->fresh());
        $outcome['extend expired deadline'] = $this->patch(route('admin.jobs.update', $job), $this->payload(['application_deadline' => '2026-04-30']))->status();
        $outcome['status after extending'] = $job->fresh()->status;
        $outcome['republish'] = $this->post(route('admin.jobs.publish', $job))->status();
        $outcome['status after republish'] = $job->fresh()->status;

        // Delete, removing the logo.
        $outcome['delete'] = $this->delete(route('admin.jobs.destroy', $job))->status();
        $outcome['job deleted'] = JobListing::query()->whereKey($job->id)->doesntExist();
        $outcome['logo deleted with job'] = ! $disk->exists($secondLogo);

        return $outcome;
    }

    /**
     * The management actions rendered for `$job` on the index page.
     *
     * @return list<string>
     */
    private function visibleActions(JobListing $job): array
    {
        $html = $this->get(route('admin.jobs.index'))->getContent();
        $actions = [
            'edit' => route('admin.jobs.edit', $job),
            'publish' => route('admin.jobs.publish', $job),
            'unpublish' => route('admin.jobs.unpublish', $job),
            'delete' => route('admin.jobs.destroy', $job).'"',
        ];

        return array_keys(array_filter($actions, fn (string $url) => str_contains($html, $url)));
    }

    public function test_admin_and_editor_get_identical_results_for_every_jobs_management_action(): void
    {
        $admin = $this->exerciseEveryAction($this->admin());
        $editor = $this->exerciseEveryAction($this->editor());

        $this->assertSame($admin, $editor, 'Admin and Editor must have identical Jobs management access.');

        // And the shared result is full access, not equal refusal.
        $this->assertSame([
            'dashboard shows Jobs link' => true,
            'index ?status=' => 200,
            'index ?status=draft' => 200,
            'index ?status=published' => 200,
            'index ?status=expired' => 200,
            'create page' => 200,
            'store' => 302,
            'stored as draft' => JobListing::STATUS_DRAFT,
            'logo uploaded' => true,
            'show page' => 200,
            'edit page' => 200,
            'index actions for draft' => ['edit', 'publish', 'delete'],
            'update draft without url' => 302,
            'still draft after save' => JobListing::STATUS_DRAFT,
            'replace logo' => 302,
            'old logo removed' => true,
            'new logo stored' => true,
            'publish' => 302,
            'status after publish' => JobListing::STATUS_PUBLISHED,
            'index actions for published' => ['edit', 'unpublish', 'delete'],
            'unpublish' => 302,
            'status after unpublish' => JobListing::STATUS_DRAFT,
            'status after expiry' => JobListing::STATUS_EXPIRED,
            'expired listed' => true,
            'index actions for expired' => ['edit', 'publish', 'delete'],
            'extend expired deadline' => 302,
            'status after extending' => JobListing::STATUS_EXPIRED,
            'republish' => 302,
            'status after republish' => JobListing::STATUS_PUBLISHED,
            'delete' => 302,
            'job deleted' => true,
            'logo deleted with job' => true,
        ], $editor);
    }

    public function test_the_policy_grants_admin_and_editor_every_job_ability(): void
    {
        $job = JobListing::factory()->expired()->create();

        foreach ([$this->admin(), $this->editor()] as $user) {
            $this->assertTrue($user->can('viewAny', JobListing::class));
            $this->assertTrue($user->can('create', JobListing::class));

            foreach (['view', 'update', 'delete', 'publish'] as $ability) {
                $this->assertTrue($user->can($ability, $job), "{$user->role} must be able to {$ability} a job.");
            }
        }
    }
}
