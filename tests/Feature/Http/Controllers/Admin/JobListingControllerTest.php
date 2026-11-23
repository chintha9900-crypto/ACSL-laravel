<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Actions\Content\UpdateJobListingLogo;
use App\Models\JobListing;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\Concerns\CreatesUploads;
use Tests\MysqlTestCase;

class JobListingControllerTest extends MysqlTestCase
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
            'title' => 'First Officer A320',
            'company_name' => 'Example Air',
            'category' => 'pilots',
            'employment_type' => 'full_time',
            'location_type' => 'local',
            'location' => 'Colombo',
            'country' => 'Sri Lanka',
            'short_description' => 'Join our A320 fleet.',
            'description' => '<p>The full description.</p>',
            'responsibilities' => '<ul><li>Fly safely</li></ul>',
            'qualifications' => '',
            'experience' => '',
            'additional_requirements' => '',
            'salary' => '',
            'date_posted' => '',
            'application_deadline' => '2026-03-31',
            'application_url' => 'https://example.com/careers/first-officer',
            ...$overrides,
        ];
    }

    // --- authorization ------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    private function routes(JobListing $job): array
    {
        return [
            ['get', route('admin.jobs.index')],
            ['get', route('admin.jobs.create')],
            ['post', route('admin.jobs.store')],
            ['get', route('admin.jobs.show', $job)],
            ['get', route('admin.jobs.edit', $job)],
            ['patch', route('admin.jobs.update', $job)],
            ['delete', route('admin.jobs.destroy', $job)],
            ['post', route('admin.jobs.publish', $job)],
            ['post', route('admin.jobs.unpublish', $job)],
        ];
    }

    public function test_a_guest_is_sent_to_login_from_every_jobs_management_route(): void
    {
        $job = JobListing::factory()->create();

        foreach ($this->routes($job) as [$method, $url]) {
            $this->{$method}($url, $this->payload())->assertRedirect(route('login'));
        }
    }

    public function test_members_and_dev_users_are_refused_every_jobs_management_route(): void
    {
        $job = JobListing::factory()->create();

        foreach ([$this->member(), $this->dev()] as $user) {
            foreach ($this->routes($job) as [$method, $url]) {
                $this->actingAs($user)->{$method}($url, $this->payload())->assertForbidden();
            }
        }

        $this->assertSame(JobListing::STATUS_DRAFT, $job->fresh()->status);
        $this->assertSame(1, JobListing::query()->count());
    }

    public function test_an_inactive_editor_cannot_reach_jobs_management(): void
    {
        $editor = User::factory()->editor()->create(['status' => 'suspended']);

        $this->actingAs($editor)->get(route('admin.jobs.index'))->assertRedirect(route('login'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function managers(): array
    {
        return ['admin' => ['admin'], 'editor' => ['editor']];
    }

    #[DataProvider('managers')]
    public function test_admin_and_editor_can_open_the_management_pages(string $role): void
    {
        $user = $this->{$role}();
        $job = JobListing::factory()->create();

        $this->actingAs($user)->get(route('admin.jobs.index'))->assertOk()->assertSee('Jobs Management')->assertSee('Add New Job');
        $this->actingAs($user)->get(route('admin.jobs.create'))->assertOk();
        $this->actingAs($user)->get(route('admin.jobs.show', $job))->assertOk()->assertSee($job->title);
        $this->actingAs($user)->get(route('admin.jobs.edit', $job))->assertOk();
    }

    #[DataProvider('managers')]
    public function test_admin_and_editor_can_create_edit_publish_and_unpublish(string $role): void
    {
        $user = $this->{$role}();

        $this->actingAs($user)->post(route('admin.jobs.store'), $this->payload())->assertRedirect(route('admin.jobs.index'));
        $job = JobListing::query()->sole();
        $this->assertSame(JobListing::STATUS_DRAFT, $job->status);
        $this->assertSame($user->id, $job->created_by_user_id);
        $this->assertSame('first-officer-a320', $job->slug);

        $this->actingAs($user)->patch(route('admin.jobs.update', $job), $this->payload(['title' => 'Captain A320']))->assertRedirect(route('admin.jobs.index'));
        $this->assertSame('Captain A320', $job->fresh()->title);
        $this->assertSame('first-officer-a320', $job->fresh()->slug);

        $this->actingAs($user)->post(route('admin.jobs.publish', $job))->assertSessionHasNoErrors();
        $this->assertSame(JobListing::STATUS_PUBLISHED, $job->fresh()->status);

        $this->actingAs($user)->post(route('admin.jobs.unpublish', $job))->assertSessionHasNoErrors();
        $this->assertSame(JobListing::STATUS_DRAFT, $job->fresh()->status);
    }

    #[DataProvider('managers')]
    public function test_admin_and_editor_can_delete_a_job(string $role): void
    {
        $job = JobListing::factory()->published()->create();

        $this->actingAs($this->{$role}())->delete(route('admin.jobs.destroy', $job))->assertRedirect(route('admin.jobs.index'));

        $this->assertModelMissing($job);
    }

    // --- create and edit validation -----------------------------------------

    public function test_required_fields_are_validated(): void
    {
        $required = ['title', 'company_name', 'category', 'employment_type', 'location_type', 'location', 'country', 'short_description', 'description', 'application_deadline'];

        $this->actingAs($this->admin())
            ->post(route('admin.jobs.store'), array_fill_keys($required, ''))
            ->assertSessionHasErrors($required);

        $this->assertSame(0, JobListing::query()->count());
    }

    public function test_values_outside_the_approved_lists_are_rejected(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.jobs.store'), $this->payload(['category' => 'astronaut', 'employment_type' => 'volunteer', 'location_type' => 'remote']))
            ->assertSessionHasErrors(['category', 'employment_type', 'location_type']);

        $this->assertSame(0, JobListing::query()->count());
    }

    public function test_text_lengths_and_dates_are_validated(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.jobs.store'), $this->payload([
                'title' => str_repeat('a', 256),
                'short_description' => str_repeat('a', 501),
                'salary' => str_repeat('a', 256),
                'application_deadline' => 'next week',
                'date_posted' => '31/03/2026',
            ]))
            ->assertSessionHasErrors(['title', 'short_description', 'salary', 'application_deadline', 'date_posted']);
    }

    public function test_a_draft_can_be_saved_without_an_application_url(): void
    {
        $this->actingAs($this->admin())->post(route('admin.jobs.store'), $this->payload(['application_url' => '']))->assertSessionHasNoErrors();

        $job = JobListing::query()->sole();
        $this->assertNull($job->application_url);
        $this->assertSame(JobListing::STATUS_DRAFT, $job->status);
    }

    public function test_a_draft_can_be_saved_with_a_past_deadline(): void
    {
        $this->actingAs($this->admin())->post(route('admin.jobs.store'), $this->payload(['application_deadline' => '2026-03-01']))->assertSessionHasNoErrors();

        $this->assertSame('2026-03-01', JobListing::query()->sole()->application_deadline->toDateString());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unsafeUrls(): array
    {
        return [
            'javascript' => ['javascript:alert(1)'],
            'data' => ['data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg=='],
            'ftp' => ['ftp://example.com/jobs'],
            'no scheme' => ['example.com/jobs'],
            'no host' => ['https://'],
            'single label host' => ['http://localhost/jobs'],
            'malformed' => ['https://exa mple.com/jobs'],
            'protocol relative' => ['//example.com/jobs'],
        ];
    }

    #[DataProvider('unsafeUrls')]
    public function test_unsafe_or_malformed_application_urls_are_rejected_on_save(string $url): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.jobs.store'), $this->payload(['application_url' => $url]))
            ->assertSessionHasErrors('application_url');

        $this->assertSame(0, JobListing::query()->count());
    }

    #[DataProvider('unsafeUrls')]
    public function test_a_stored_unsafe_application_url_blocks_publishing(string $url): void
    {
        $job = JobListing::factory()->create();
        $job->forceFill(['application_url' => $url])->save();

        $this->actingAs($this->admin())->post(route('admin.jobs.publish', $job))->assertSessionHasErrors('application_url');

        $this->assertSame(JobListing::STATUS_DRAFT, $job->fresh()->status);
    }

    public function test_status_and_slug_cannot_be_set_through_the_form(): void
    {
        $this->actingAs($this->admin())->post(route('admin.jobs.store'), $this->payload([
            'status' => 'published',
            'published_at' => '2026-01-01 00:00:00',
            'slug' => 'chosen-slug',
            'created_by_user_id' => 999,
        ]));

        $job = JobListing::query()->sole();
        $this->assertSame(JobListing::STATUS_DRAFT, $job->status);
        $this->assertNull($job->published_at);
        $this->assertSame('first-officer-a320', $job->slug);
        $this->assertNotSame(999, $job->created_by_user_id);
    }

    public function test_duplicate_titles_get_unique_slugs(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.jobs.store'), $this->payload());
        $this->actingAs($admin)->post(route('admin.jobs.store'), $this->payload());

        $this->assertSame(['first-officer-a320', 'first-officer-a320-2'], JobListing::query()->orderBy('id')->pluck('slug')->all());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function statuses(): array
    {
        return ['draft' => ['draft'], 'published' => ['published'], 'expired' => ['expired']];
    }

    #[DataProvider('statuses')]
    public function test_editing_never_changes_the_status(string $status): void
    {
        $job = match ($status) {
            'published' => JobListing::factory()->published()->create(),
            'expired' => JobListing::factory()->expired()->create(),
            default => JobListing::factory()->create(),
        };
        $publishedAt = $job->fresh()->published_at;

        $this->actingAs($this->editor())
            ->patch(route('admin.jobs.update', $job), $this->payload(['application_deadline' => '2026-12-31']))
            ->assertSessionHasNoErrors();

        $this->assertSame($status, $job->fresh()->status);
        $this->assertEquals($publishedAt, $job->fresh()->published_at);
    }

    // --- publishing ----------------------------------------------------------

    public function test_publishing_sets_status_published_at_and_date_posted(): void
    {
        $job = JobListing::factory()->deadline('2026-03-31')->create(['date_posted' => null]);

        $this->actingAs($this->admin())->post(route('admin.jobs.publish', $job))->assertSessionHasNoErrors();

        $fresh = $job->fresh();
        $this->assertSame(JobListing::STATUS_PUBLISHED, $fresh->status);
        $this->assertTrue($fresh->published_at->equalTo(now()));
        $this->assertSame('2026-03-10', $fresh->date_posted->toDateString());
        $this->assertTrue($fresh->isPubliclyVisible());
    }

    public function test_publishing_keeps_an_existing_date_posted(): void
    {
        $job = JobListing::factory()->create(['date_posted' => '2026-03-02']);

        $this->actingAs($this->admin())->post(route('admin.jobs.publish', $job));

        $this->assertSame('2026-03-02', $job->fresh()->date_posted->toDateString());
    }

    public function test_publishing_without_an_application_url_fails(): void
    {
        $job = JobListing::factory()->create(['application_url' => null]);

        $this->actingAs($this->admin())->post(route('admin.jobs.publish', $job))->assertSessionHasErrors('application_url');

        $this->assertSame(JobListing::STATUS_DRAFT, $job->fresh()->status);
        $this->assertNull($job->fresh()->published_at);
    }

    public function test_publishing_with_a_past_deadline_fails(): void
    {
        $job = JobListing::factory()->deadline('2026-03-09')->create();

        $this->actingAs($this->admin())->post(route('admin.jobs.publish', $job))->assertSessionHasErrors('application_deadline');

        $this->assertSame(JobListing::STATUS_DRAFT, $job->fresh()->status);
    }

    public function test_todays_deadline_can_be_published(): void
    {
        $job = JobListing::factory()->deadline('2026-03-10')->create();

        $this->actingAs($this->admin())->post(route('admin.jobs.publish', $job))->assertSessionHasNoErrors();

        $this->assertSame(JobListing::STATUS_PUBLISHED, $job->fresh()->status);
    }

    public function test_the_publish_deadline_check_uses_colombo_time(): void
    {
        $job = JobListing::factory()->deadline('2026-03-10')->create();

        // 00:30 on 11 March in Colombo, still 10 March in UTC.
        $this->travelTo(CarbonImmutable::parse('2026-03-11 00:30', JobListing::DEADLINE_TIMEZONE));
        $this->actingAs($this->admin())->post(route('admin.jobs.publish', $job))->assertSessionHasErrors('application_deadline');

        $this->assertSame(JobListing::STATUS_DRAFT, $job->fresh()->status);
    }

    public function test_an_expired_job_is_republished_only_by_an_explicit_publish_after_extending_its_deadline(): void
    {
        $job = JobListing::factory()->expired()->create();
        $editor = $this->editor();

        $this->actingAs($editor)->post(route('admin.jobs.publish', $job))->assertSessionHasErrors('application_deadline');
        $this->assertSame(JobListing::STATUS_EXPIRED, $job->fresh()->status);

        $this->actingAs($editor)->patch(route('admin.jobs.update', $job), $this->payload(['application_deadline' => '2026-04-30']))->assertSessionHasNoErrors();
        $this->assertSame(JobListing::STATUS_EXPIRED, $job->fresh()->status, 'Extending the deadline alone must not republish.');
        $this->assertFalse($job->fresh()->isPubliclyVisible());

        $this->actingAs($editor)->post(route('admin.jobs.publish', $job))->assertSessionHasNoErrors();
        $this->assertSame(JobListing::STATUS_PUBLISHED, $job->fresh()->status);
        $this->assertTrue($job->fresh()->isPubliclyVisible());
    }

    public function test_unpublishing_returns_to_draft_and_keeps_the_content(): void
    {
        $job = JobListing::factory()->published()->create();
        $before = $job->fresh()->only(['title', 'description', 'application_deadline', 'application_url', 'published_at']);

        $this->actingAs($this->admin())->post(route('admin.jobs.unpublish', $job))->assertSessionHasNoErrors();

        $fresh = $job->fresh();
        $this->assertSame(JobListing::STATUS_DRAFT, $fresh->status);
        $this->assertEquals($before, $fresh->only(array_keys($before)));
    }

    // --- rich text ------------------------------------------------------------

    public function test_the_form_has_one_editor_per_rich_text_field_with_unique_ids(): void
    {
        $html = $this->actingAs($this->admin())->get(route('admin.jobs.create'))->assertOk()->getContent();

        foreach (['description', 'responsibilities', 'qualifications', 'experience', 'additional_requirements'] as $field) {
            $this->assertSame(1, substr_count($html, 'data-rte-root="'.$field.'"'));
            $this->assertSame(1, substr_count($html, 'id="'.$field.'-editor"'));
            $this->assertSame(1, substr_count($html, 'name="'.$field.'"'));
            $this->assertSame(1, substr_count($html, '})("'.$field.'");'));
        }

        preg_match_all('/\sid="([^"]+)"/', $html, $ids);
        $this->assertSame(array_unique($ids[1]), $ids[1], 'Every id on the form must be unique.');
        $this->assertStringContainsString('Visible until the end of this date (Asia/Colombo time)', $html);
    }

    public function test_rich_text_is_saved_to_the_correct_fields_and_sanitised(): void
    {
        $this->actingAs($this->admin())->post(route('admin.jobs.store'), $this->payload([
            'description' => '<p>Desc <script>alert(1)</script><a href="javascript:alert(1)">x</a></p>',
            'responsibilities' => '<ul><li>Resp</li></ul><img src=x onerror=alert(1)>',
            'qualifications' => '<p onclick="steal()">Quals</p>',
            'experience' => '<p><br></p>',
            'additional_requirements' => '<p>Extra</p>',
        ]))->assertSessionHasNoErrors();

        $job = JobListing::query()->sole();
        $this->assertSame('<p>Desc <a>x</a></p>', $job->description);
        $this->assertSame('<ul><li>Resp</li></ul>', $job->responsibilities);
        $this->assertSame('<p>Quals</p>', $job->qualifications);
        $this->assertNull($job->experience, 'An empty optional editor is stored as null.');
        $this->assertSame('<p>Extra</p>', $job->additional_requirements);
    }

    public function test_validation_errors_show_against_the_right_editor(): void
    {
        $response = $this->actingAs($this->admin())
            ->from(route('admin.jobs.create'))
            ->followingRedirects()
            ->post(route('admin.jobs.store'), $this->payload(['description' => '', 'responsibilities' => '<p>Keep me</p>']));

        $html = $response->getContent();
        $this->assertStringContainsString('id="description-error"', $html);
        $this->assertStringNotContainsString('id="responsibilities-error"', $html);
        $this->assertStringContainsString('Keep me', $html);
    }

    // --- logo uploads ----------------------------------------------------------

    public function test_a_valid_logo_is_stored_under_a_random_name(): void
    {
        $this->actingAs($this->admin())->post(route('admin.jobs.store'), $this->payload(['company_logo' => $this->pngUpload('company.png')]))->assertSessionHasNoErrors();

        $path = JobListing::query()->sole()->company_logo;
        $this->assertMatchesRegularExpression('#^jobs/[0-9a-z]{26}\.png$#', $path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_non_image_and_disguised_files_are_rejected(): void
    {
        $admin = $this->admin();

        foreach ([
            $this->pdfUpload('logo.pdf'),
            $this->realUpload('logo.png', $this->pdfContent(), 'image/png'),
            $this->realUpload('logo.php', '<?php echo 1;', 'image/png'),
            $this->realUpload('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'image/svg+xml'),
        ] as $file) {
            $this->actingAs($admin)->post(route('admin.jobs.store'), $this->payload(['company_logo' => $file]))->assertSessionHasErrors('company_logo');
        }

        $this->assertSame(0, JobListing::query()->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_an_oversized_logo_is_rejected(): void
    {
        $big = $this->realUpload('big.png', $this->pngContent().str_repeat("\0", (config('uploads.job_logo.max_kb') + 1) * 1024), 'image/png');

        $this->actingAs($this->admin())->post(route('admin.jobs.store'), $this->payload(['company_logo' => $big]))->assertSessionHasErrors('company_logo');

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_replacing_a_logo_removes_the_previous_file_and_editing_without_one_keeps_it(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.jobs.store'), $this->payload(['company_logo' => $this->pngUpload()]));
        $job = JobListing::query()->sole();
        $first = $job->company_logo;

        $this->actingAs($admin)->patch(route('admin.jobs.update', $job), $this->payload());
        $this->assertSame($first, $job->fresh()->company_logo);

        $this->actingAs($admin)->patch(route('admin.jobs.update', $job), $this->payload(['company_logo' => $this->pngUpload('second.png')]));
        $second = $job->fresh()->company_logo;

        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
        $this->actingAs($admin)->get(route('admin.jobs.edit', $job))->assertSee(Storage::disk('public')->url($second));
    }

    public function test_deleting_a_job_removes_its_logo(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.jobs.store'), $this->payload(['company_logo' => $this->pngUpload()]));
        $job = JobListing::query()->sole();

        $this->actingAs($admin)->delete(route('admin.jobs.destroy', $job));

        $this->assertModelMissing($job);
        Storage::disk('public')->assertMissing($job->company_logo);
    }

    /**
     * @return array<string, array{0: bool}>
     */
    public static function failedSaves(): array
    {
        return ['save returns false' => [false], 'save throws' => [true]];
    }

    #[DataProvider('failedSaves')]
    public function test_a_failed_database_update_keeps_the_old_logo_and_removes_the_new_file(bool $throws): void
    {
        $job = JobListing::factory()->create();
        app(UpdateJobListingLogo::class)->handle($job, $this->pngUpload());
        $original = $job->fresh()->company_logo;

        JobListing::updating(function () use ($throws) {
            if ($throws) {
                throw new RuntimeException('Database unavailable.');
            }

            return false;
        });

        try {
            app(UpdateJobListingLogo::class)->handle($job->fresh(), $this->pngUpload('second.png'));
            $this->fail('A failed save must not report success.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame($original, $job->fresh()->company_logo);
        $this->assertSame([$original], Storage::disk('public')->allFiles());
    }

    // --- removing a logo -------------------------------------------------------

    private function jobWithLogo(): JobListing
    {
        $job = JobListing::factory()->published()->create();
        app(UpdateJobListingLogo::class)->handle($job, $this->pngUpload());

        return $job->fresh();
    }

    #[DataProvider('managers')]
    public function test_admin_and_editor_can_remove_the_current_logo(string $role): void
    {
        $job = $this->jobWithLogo();
        $logo = $job->company_logo;
        $before = $job->only(['title', 'description', 'status', 'application_deadline', 'application_url', 'published_at', 'slug']);

        $this->actingAs($this->{$role}())
            ->patch(route('admin.jobs.update', $job), $this->payload([...$job->only(['title']), 'remove_company_logo' => '1']))
            ->assertRedirect(route('admin.jobs.index'))
            ->assertSessionHas('status', 'Job updated and logo removed.');

        $fresh = $job->fresh();
        $this->assertNull($fresh->company_logo);
        Storage::disk('public')->assertMissing($logo);
        $this->assertSame(JobListing::STATUS_PUBLISHED, $fresh->status);
        $this->assertSame($before['slug'], $fresh->slug);
        $this->assertEquals($before['published_at'], $fresh->published_at);
    }

    public function test_the_edit_form_offers_the_checkbox_only_when_there_is_a_logo(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.jobs.edit', $this->jobWithLogo()))->assertSee('Remove current logo')->assertSee('name="remove_company_logo"', false);
        $this->actingAs($admin)->get(route('admin.jobs.edit', JobListing::factory()->create()))->assertDontSee('Remove current logo');
        $this->actingAs($admin)->get(route('admin.jobs.create'))->assertDontSee('Remove current logo');
    }

    public function test_the_logo_is_kept_when_the_checkbox_is_not_selected(): void
    {
        $job = $this->jobWithLogo();
        $logo = $job->company_logo;

        $this->actingAs($this->editor())
            ->patch(route('admin.jobs.update', $job), $this->payload(['remove_company_logo' => '0']))
            ->assertSessionHas('status', 'Job updated.');

        $this->assertSame($logo, $job->fresh()->company_logo);
        Storage::disk('public')->assertExists($logo);
    }

    public function test_a_new_upload_takes_priority_over_removal(): void
    {
        $job = $this->jobWithLogo();
        $old = $job->company_logo;

        $this->actingAs($this->admin())
            ->patch(route('admin.jobs.update', $job), $this->payload(['remove_company_logo' => '1', 'company_logo' => $this->pngUpload('replacement.png')]))
            ->assertSessionHasNoErrors();

        $new = $job->fresh()->company_logo;
        $this->assertNotNull($new);
        $this->assertNotSame($old, $new);
        Storage::disk('public')->assertMissing($old);
        Storage::disk('public')->assertExists($new);
    }

    public function test_an_invalid_removal_value_is_rejected(): void
    {
        $job = $this->jobWithLogo();

        $this->actingAs($this->admin())
            ->patch(route('admin.jobs.update', $job), $this->payload(['remove_company_logo' => 'please']))
            ->assertSessionHasErrors('remove_company_logo');

        Storage::disk('public')->assertExists($job->company_logo);
    }

    #[DataProvider('failedSaves')]
    public function test_a_failed_removal_keeps_the_reference_and_the_file(bool $throws): void
    {
        $job = $this->jobWithLogo();
        $logo = $job->company_logo;

        JobListing::updating(function () use ($throws) {
            if ($throws) {
                throw new RuntimeException('Database unavailable.');
            }

            return false;
        });

        try {
            app(UpdateJobListingLogo::class)->remove($job);
            $this->fail('A failed save must not report success.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame($logo, $job->company_logo);
        $this->assertSame($logo, $job->fresh()->company_logo);
        Storage::disk('public')->assertExists($logo);
    }

    // --- silent save failures (Eloquent save()/delete() returning false) --------

    /**
     * Asserts the request came back with the "not saved" error and no success message.
     */
    private function assertNotSavedResponse(TestResponse $response): void
    {
        $response->assertRedirect()->assertSessionHasErrors('job')->assertSessionMissing('status');
    }

    #[DataProvider('managers')]
    public function test_a_silent_save_failure_during_an_ordinary_edit_changes_nothing(string $role): void
    {
        $job = $this->jobWithLogo();
        $before = $job->fresh()->getAttributes();
        JobListing::updating(fn () => false);

        $response = $this->actingAs($this->{$role}())
            ->from(route('admin.jobs.edit', $job))
            ->patch(route('admin.jobs.update', $job), $this->payload(['title' => 'Changed title']));

        $this->assertNotSavedResponse($response);
        $response->assertRedirect(route('admin.jobs.edit', $job));
        $this->assertSame($before, $job->fresh()->getAttributes());
        $this->assertSame([$before['company_logo']], Storage::disk('public')->allFiles());
    }

    public function test_the_not_saved_message_is_shown_on_the_form_with_the_input_kept(): void
    {
        $job = JobListing::factory()->create();
        JobListing::updating(fn () => false);

        $this->actingAs($this->editor())
            ->from(route('admin.jobs.edit', $job))
            ->followingRedirects()
            ->patch(route('admin.jobs.update', $job), $this->payload(['title' => 'Changed title']))
            ->assertOk()
            ->assertSee('The job could not be updated. Your changes were not saved')
            ->assertSee('value="Changed title"', false)
            ->assertDontSee('Job updated.');
    }

    #[DataProvider('managers')]
    public function test_a_silent_save_failure_during_logo_removal_keeps_the_logo(string $role): void
    {
        $job = $this->jobWithLogo();
        $logo = $job->company_logo;
        JobListing::updating(fn (JobListing $updating) => ! $updating->isDirty('company_logo'));

        $this->assertNotSavedResponse($this->actingAs($this->{$role}())
            ->patch(route('admin.jobs.update', $job), $this->payload(['title' => 'Changed title', 'remove_company_logo' => '1'])));

        $fresh = $job->fresh();
        $this->assertSame($logo, $fresh->company_logo);
        $this->assertNotSame('Changed title', $fresh->title, 'The field changes must be rolled back with the failed logo step.');
        $this->assertSame([$logo], Storage::disk('public')->allFiles());
    }

    #[DataProvider('managers')]
    public function test_a_silent_save_failure_during_logo_replacement_keeps_the_old_logo_and_removes_the_new_file(string $role): void
    {
        $job = $this->jobWithLogo();
        $logo = $job->company_logo;
        JobListing::updating(fn (JobListing $updating) => ! $updating->isDirty('company_logo'));

        $this->assertNotSavedResponse($this->actingAs($this->{$role}())
            ->patch(route('admin.jobs.update', $job), $this->payload(['title' => 'Changed title', 'company_logo' => $this->pngUpload('new.png')])));

        $fresh = $job->fresh();
        $this->assertSame($logo, $fresh->company_logo);
        $this->assertNotSame('Changed title', $fresh->title);
        $this->assertSame([$logo], Storage::disk('public')->allFiles());
    }

    public function test_a_silent_failure_while_creating_keeps_nothing(): void
    {
        JobListing::creating(fn () => false);

        $this->assertNotSavedResponse($this->actingAs($this->admin())
            ->post(route('admin.jobs.store'), $this->payload(['company_logo' => $this->pngUpload()])));

        $this->assertSame(0, JobListing::query()->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_a_silent_failure_saving_the_logo_of_a_new_job_rolls_the_job_back(): void
    {
        JobListing::updating(fn (JobListing $updating) => ! $updating->isDirty('company_logo'));

        $this->assertNotSavedResponse($this->actingAs($this->editor())
            ->post(route('admin.jobs.store'), $this->payload(['company_logo' => $this->pngUpload()])));

        $this->assertSame(0, JobListing::query()->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_silent_failures_when_publishing_unpublishing_or_deleting_change_nothing(): void
    {
        $draft = JobListing::factory()->create();
        $published = $this->jobWithLogo();
        $logo = $published->company_logo;
        $editor = $this->editor();

        JobListing::updating(fn () => false);
        JobListing::deleting(fn () => false);

        $this->assertNotSavedResponse($this->actingAs($editor)->post(route('admin.jobs.publish', $draft)));
        $this->assertSame(JobListing::STATUS_DRAFT, $draft->fresh()->status);

        $this->assertNotSavedResponse($this->actingAs($editor)->post(route('admin.jobs.unpublish', $published)));
        $this->assertSame(JobListing::STATUS_PUBLISHED, $published->fresh()->status);

        $this->assertNotSavedResponse($this->actingAs($editor)->delete(route('admin.jobs.destroy', $published)));
        $this->assertModelExists($published);
        $this->assertSame($logo, $published->fresh()->company_logo);
        Storage::disk('public')->assertExists($logo);
    }

    // --- management interface -------------------------------------------------

    public function test_the_status_filters_show_only_matching_jobs(): void
    {
        $draft = JobListing::factory()->create(['title' => 'Draft role']);
        $published = JobListing::factory()->published()->create(['title' => 'Published role']);
        $expired = JobListing::factory()->expired()->create(['title' => 'Expired role']);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.jobs.index'))->assertSee([$draft->title, $published->title, $expired->title]);
        $this->actingAs($admin)->get(route('admin.jobs.index', ['status' => 'draft']))->assertSee($draft->title)->assertDontSee([$published->title, $expired->title]);
        $this->actingAs($admin)->get(route('admin.jobs.index', ['status' => 'published']))->assertSee($published->title)->assertDontSee([$draft->title, $expired->title]);
        $this->actingAs($admin)->get(route('admin.jobs.index', ['status' => 'expired']))->assertSee($expired->title)->assertDontSee([$draft->title, $published->title]);
    }

    public function test_search_matches_title_or_company_and_keeps_the_status_filter(): void
    {
        JobListing::factory()->create(['title' => 'Cabin Crew Member', 'company_name' => 'Sky One']);
        JobListing::factory()->published()->create(['title' => 'Engineer', 'company_name' => 'Cabin Works']);
        JobListing::factory()->create(['title' => 'Dispatcher', 'company_name' => 'Ground Co']);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.jobs.index', ['q' => 'cabin']))->assertSee(['Cabin Crew Member', 'Engineer'])->assertDontSee('Dispatcher');
        $this->actingAs($admin)->get(route('admin.jobs.index', ['q' => 'cabin', 'status' => 'published']))->assertSee('Engineer')->assertDontSee(['Cabin Crew Member', 'Dispatcher']);
    }

    public function test_expired_jobs_are_kept_and_listed(): void
    {
        $expired = JobListing::factory()->expired()->create();

        $this->artisan('jobs:expire')->assertSuccessful();

        $this->assertModelExists($expired);
        $this->actingAs($this->editor())->get(route('admin.jobs.index', ['status' => 'expired']))->assertSee($expired->title);
    }

    public function test_actions_follow_the_status(): void
    {
        $admin = $this->admin();
        $draft = JobListing::factory()->create();
        $published = JobListing::factory()->published()->create();
        $expired = JobListing::factory()->expired()->create();

        $this->actingAs($admin)->get(route('admin.jobs.show', $draft))
            ->assertSee(route('admin.jobs.publish', $draft))->assertDontSee(route('admin.jobs.unpublish', $draft));
        $this->actingAs($admin)->get(route('admin.jobs.show', $published))
            ->assertSee(route('admin.jobs.unpublish', $published))->assertDontSee(route('admin.jobs.publish', $published));
        $this->actingAs($admin)->get(route('admin.jobs.show', $expired))
            ->assertSee(route('admin.jobs.publish', $expired))->assertSee('Republish');
    }

    public function test_admin_and_editor_see_the_jobs_link_and_other_roles_do_not(): void
    {
        $link = 'href="'.route('admin.jobs.index').'"';

        $this->actingAs($this->admin())->get(route('admin.dashboard'))->assertOk()->assertSee($link, false);
        $this->actingAs($this->editor())->get(route('editor.dashboard'))->assertOk()->assertSee($link, false);
        $this->actingAs($this->dev())->get(route('dev.dashboard'))->assertOk()->assertDontSee($link, false);
        $this->actingAs($this->member())->get(route('member.dashboard'))->assertDontSee($link, false);
    }
}
