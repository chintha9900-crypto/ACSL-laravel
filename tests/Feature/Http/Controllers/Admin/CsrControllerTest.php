<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\CsrProject;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\Concerns\CreatesUploads;
use Tests\MysqlTestCase;

class CsrControllerTest extends MysqlTestCase
{
    use CreatesReviewableApplications;
    use CreatesUploads;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'title' => 'A New Community Project',
            'slug' => 'a-new-community-project',
            'content' => '<p>The body.</p>',
            ...$overrides,
        ];
    }

    // --- authorization ------------------------------------------------------

    public function test_a_guest_cannot_reach_any_admin_csr_route(): void
    {
        $project = CsrProject::factory()->create();

        $this->get(route('admin.csr.index'))->assertRedirect(route('login'));
        $this->get(route('admin.csr.create'))->assertRedirect(route('login'));
        $this->post(route('admin.csr.store'), $this->payload())->assertRedirect(route('login'));
        $this->get(route('admin.csr.show', $project))->assertRedirect(route('login'));
        $this->get(route('admin.csr.edit', $project))->assertRedirect(route('login'));
        $this->patch(route('admin.csr.update', $project), $this->payload())->assertRedirect(route('login'));
        $this->delete(route('admin.csr.destroy', $project))->assertRedirect(route('login'));
        $this->post(route('admin.csr.publish', $project))->assertRedirect(route('login'));
    }

    public function test_a_non_admin_member_cannot_reach_any_admin_csr_route(): void
    {
        $project = CsrProject::factory()->create();
        $member = $this->member();

        $this->actingAs($member)->get(route('admin.csr.index'))->assertForbidden();
        $this->actingAs($member)->get(route('admin.csr.create'))->assertForbidden();
        $this->actingAs($member)->post(route('admin.csr.store'), $this->payload())->assertForbidden();
        $this->actingAs($member)->get(route('admin.csr.show', $project))->assertForbidden();
        $this->actingAs($member)->get(route('admin.csr.edit', $project))->assertForbidden();
        $this->actingAs($member)->patch(route('admin.csr.update', $project), $this->payload())->assertForbidden();
        $this->actingAs($member)->delete(route('admin.csr.destroy', $project))->assertForbidden();
        $this->actingAs($member)->post(route('admin.csr.publish', $project))->assertForbidden();
    }

    public function test_an_inactive_admin_cannot_reach_any_admin_csr_route(): void
    {
        $inactiveAdmin = User::factory()->create(['role' => 'admin', 'status' => 'suspended']);

        $this->actingAs($inactiveAdmin)->get(route('admin.csr.index'))->assertRedirect(route('login'));
    }

    public function test_an_admin_can_access_the_csr_dashboard_and_create_page(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/csr')->assertOk();
        $this->actingAs($admin)->get('/admin/csr/create')->assertOk();
    }

    public function test_an_admin_can_list_csr_projects(): void
    {
        CsrProject::factory()->create(['title' => 'Visible To Admin']);

        $this->actingAs($this->admin())
            ->get(route('admin.csr.index'))
            ->assertOk()
            ->assertSee('Visible To Admin');
    }

    public function test_an_admin_can_view_a_single_csr_project(): void
    {
        $project = CsrProject::factory()->create(['title' => 'Detail Page Project']);

        $this->actingAs($this->admin())
            ->get(route('admin.csr.show', $project))
            ->assertOk()
            ->assertSee('Detail Page Project');
    }

    // --- create / validation -------------------------------------------------

    public function test_an_admin_can_create_a_csr_project_as_a_draft(): void
    {
        $response = $this->actingAs($this->admin())->post(route('admin.csr.store'), $this->payload());

        $response->assertRedirect(route('admin.csr.index'));
        $project = CsrProject::query()->firstOrFail();
        $this->assertSame('draft', $project->status);
        $this->assertNull($project->published_at);
        $this->assertSame('A New Community Project', $project->title);
    }

    public function test_the_creator_is_set_to_the_creating_admin(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.csr.store'), $this->payload());

        $this->assertSame($admin->id, CsrProject::query()->firstOrFail()->created_by_user_id);
    }

    public function test_duplicate_slugs_are_rejected_on_create(): void
    {
        CsrProject::factory()->create(['slug' => 'a-new-community-project']);

        $this->actingAs($this->admin())
            ->post(route('admin.csr.store'), $this->payload())
            ->assertSessionHasErrors('slug');

        $this->assertSame(1, CsrProject::query()->count());
    }

    public function test_required_fields_are_validated(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.csr.store'), [])
            ->assertSessionHasErrors(['title', 'slug', 'content']);
    }

    /**
     * Confirmed before building this CRUD (not assumed): CsrProject's own
     * docblock documents `content` as sanitised HTML, the same convention
     * as BlogPost/NewsItem, and both public CSR views render it with
     * `{!! !!}` — this is the Blog/News rule, not the Events plain-text
     * exception.
     */
    public function test_content_is_sanitised_on_save(): void
    {
        $this->actingAs($this->admin())->post(route('admin.csr.store'), $this->payload([
            'content' => '<p>Safe</p><script>alert(1)</script>',
        ]));

        $project = CsrProject::query()->firstOrFail();
        $this->assertStringNotContainsString('<script', $project->content);
        $this->assertStringContainsString('Safe', $project->content);
    }

    public function test_an_image_can_be_uploaded_on_create(): void
    {
        $this->actingAs($this->admin())->post(route('admin.csr.store'), [
            ...$this->payload(),
            'image' => $this->pngUpload(),
        ]);

        $project = CsrProject::query()->firstOrFail();
        $this->assertNotNull($project->image_path);
        Storage::disk('public')->assertExists($project->image_path);
    }

    // --- update ---------------------------------------------------------------

    public function test_an_admin_can_update_a_csr_projects_fields(): void
    {
        $project = CsrProject::factory()->create(['title' => 'Old Title']);

        $this->actingAs($this->admin())->patch(route('admin.csr.update', $project), $this->payload([
            'slug' => $project->slug,
            'title' => 'New Title',
        ]))->assertRedirect(route('admin.csr.index'));

        $this->assertSame('New Title', $project->fresh()->title);
    }

    public function test_updating_does_not_change_status_or_published_at(): void
    {
        $project = CsrProject::factory()->published()->create();
        $originalPublishedAt = $project->published_at;

        $this->actingAs($this->admin())->patch(route('admin.csr.update', $project), $this->payload([
            'slug' => $project->slug,
        ]));

        $project->refresh();
        $this->assertSame('published', $project->status);
        $this->assertTrue($originalPublishedAt->equalTo($project->published_at));
    }

    public function test_duplicate_slug_is_rejected_on_update_but_keeping_its_own_slug_is_fine(): void
    {
        $other = CsrProject::factory()->create(['slug' => 'taken-slug']);
        $project = CsrProject::factory()->create(['slug' => 'own-slug']);

        $this->actingAs($this->admin())
            ->patch(route('admin.csr.update', $project), $this->payload(['slug' => 'taken-slug']))
            ->assertSessionHasErrors('slug');

        $this->actingAs($this->admin())
            ->patch(route('admin.csr.update', $project), $this->payload(['slug' => 'own-slug']))
            ->assertSessionHasNoErrors();
    }

    public function test_replacing_an_image_deletes_the_previous_file(): void
    {
        $this->actingAs($this->admin())->post(route('admin.csr.store'), $this->payload(['slug' => 'temp-project', 'image' => $this->pngUpload()]));
        $project = CsrProject::query()->where('slug', 'temp-project')->firstOrFail();
        $firstPath = $project->image_path;

        $this->actingAs($this->admin())->patch(route('admin.csr.update', $project), [
            ...$this->payload(['slug' => 'temp-project']),
            'image' => $this->pngUpload('second.png'),
        ]);

        $project->refresh();
        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($project->image_path);
    }

    // --- publish / unpublish ----------------------------------------------------

    public function test_publishing_a_draft_stamps_published_at(): void
    {
        $project = CsrProject::factory()->create();

        $this->actingAs($this->admin())->post(route('admin.csr.publish', $project))->assertRedirect();

        $project->refresh();
        $this->assertSame('published', $project->status);
        $this->assertNotNull($project->published_at);
    }

    public function test_republishing_restamps_published_at(): void
    {
        $project = CsrProject::factory()->published()->create(['published_at' => now()->subMonth()]);
        $original = $project->published_at;

        $this->travel(1)->hour();
        $this->actingAs($this->admin())->post(route('admin.csr.publish', $project));

        $project->refresh();
        $this->assertTrue($project->published_at->greaterThan($original));
    }

    public function test_unpublishing_hides_it_from_the_public_listing(): void
    {
        $project = CsrProject::factory()->published()->create(['title' => 'Was Live']);

        $this->actingAs($this->admin())->post(route('admin.csr.unpublish', $project));

        $this->assertSame('draft', $project->fresh()->status);
        $this->get(route('csr.index'))->assertDontSee('Was Live');
    }

    // --- delete ------------------------------------------------------------------

    public function test_an_admin_can_delete_a_csr_project_and_its_image(): void
    {
        $this->actingAs($this->admin())->post(route('admin.csr.store'), [
            ...$this->payload(),
            'image' => $this->pngUpload(),
        ]);
        $project = CsrProject::query()->firstOrFail();
        $path = $project->image_path;

        $this->actingAs($this->admin())->delete(route('admin.csr.destroy', $project))->assertRedirect();

        $this->assertSame(0, CsrProject::query()->count());
        Storage::disk('public')->assertMissing($path);
    }
}
