<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\EventListing;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\Concerns\CreatesUploads;
use Tests\MysqlTestCase;

class EventControllerTest extends MysqlTestCase
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
            'title' => 'A New Fly-In',
            'slug' => 'a-new-fly-in',
            'excerpt' => 'A short summary.',
            'content' => 'The full details.',
            'starts_at' => now()->addWeek()->format('Y-m-d\TH:i'),
            'location' => 'Colombo',
            ...$overrides,
        ];
    }

    // --- authorization ------------------------------------------------------

    public function test_a_guest_cannot_reach_any_admin_events_route(): void
    {
        $event = EventListing::factory()->create();

        $this->get(route('admin.events.index'))->assertRedirect(route('login'));
        $this->get(route('admin.events.create'))->assertRedirect(route('login'));
        $this->post(route('admin.events.store'), $this->payload())->assertRedirect(route('login'));
        $this->get(route('admin.events.show', $event))->assertRedirect(route('login'));
        $this->get(route('admin.events.edit', $event))->assertRedirect(route('login'));
        $this->patch(route('admin.events.update', $event), $this->payload())->assertRedirect(route('login'));
        $this->delete(route('admin.events.destroy', $event))->assertRedirect(route('login'));
        $this->post(route('admin.events.publish', $event))->assertRedirect(route('login'));
    }

    public function test_a_non_admin_member_cannot_reach_any_admin_events_route(): void
    {
        $event = EventListing::factory()->create();
        $member = $this->member();

        $this->actingAs($member)->get(route('admin.events.index'))->assertForbidden();
        $this->actingAs($member)->get(route('admin.events.create'))->assertForbidden();
        $this->actingAs($member)->post(route('admin.events.store'), $this->payload())->assertForbidden();
        $this->actingAs($member)->get(route('admin.events.show', $event))->assertForbidden();
        $this->actingAs($member)->get(route('admin.events.edit', $event))->assertForbidden();
        $this->actingAs($member)->patch(route('admin.events.update', $event), $this->payload())->assertForbidden();
        $this->actingAs($member)->delete(route('admin.events.destroy', $event))->assertForbidden();
        $this->actingAs($member)->post(route('admin.events.publish', $event))->assertForbidden();
    }

    public function test_an_inactive_admin_cannot_reach_any_admin_events_route(): void
    {
        $inactiveAdmin = User::factory()->create(['role' => 'admin', 'status' => 'suspended']);

        $this->actingAs($inactiveAdmin)->get(route('admin.events.index'))->assertRedirect(route('login'));
    }

    public function test_an_admin_can_access_the_events_dashboard_and_create_page(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/events')->assertOk();
        $this->actingAs($admin)->get('/admin/events/create')->assertOk();
    }

    public function test_an_admin_can_list_events(): void
    {
        EventListing::factory()->create(['title' => 'Visible To Admin']);

        $this->actingAs($this->admin())
            ->get(route('admin.events.index'))
            ->assertOk()
            ->assertSee('Visible To Admin');
    }

    public function test_an_admin_can_view_a_single_event(): void
    {
        $event = EventListing::factory()->create(['title' => 'Detail Page Event']);

        $this->actingAs($this->admin())
            ->get(route('admin.events.show', $event))
            ->assertOk()
            ->assertSee('Detail Page Event');
    }

    // --- create / validation -------------------------------------------------

    public function test_an_admin_can_create_an_event_as_a_draft(): void
    {
        $response = $this->actingAs($this->admin())->post(route('admin.events.store'), $this->payload());

        $response->assertRedirect(route('admin.events.index'));
        $event = EventListing::query()->firstOrFail();
        $this->assertSame('draft', $event->status);
        $this->assertNull($event->published_at);
        $this->assertSame('A New Fly-In', $event->title);
    }

    public function test_the_starts_at_and_location_fields_are_saved(): void
    {
        $startsAt = now()->addWeek()->seconds(0)->format('Y-m-d\TH:i');

        $this->actingAs($this->admin())->post(route('admin.events.store'), $this->payload([
            'starts_at' => $startsAt,
            'location' => 'Ratmalana Airfield',
        ]));

        $event = EventListing::query()->firstOrFail();
        $this->assertSame('Ratmalana Airfield', $event->location);
        $this->assertSame($startsAt, $event->starts_at->format('Y-m-d\TH:i'));
    }

    public function test_the_creator_is_set_to_the_creating_admin(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.events.store'), $this->payload());

        $this->assertSame($admin->id, EventListing::query()->firstOrFail()->created_by_user_id);
    }

    public function test_duplicate_slugs_are_rejected_on_create(): void
    {
        EventListing::factory()->create(['slug' => 'a-new-fly-in']);

        $this->actingAs($this->admin())
            ->post(route('admin.events.store'), $this->payload())
            ->assertSessionHasErrors('slug');

        $this->assertSame(1, EventListing::query()->count());
    }

    public function test_required_fields_are_validated(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.events.store'), [])
            ->assertSessionHasErrors(['title', 'slug', 'content', 'starts_at']);
    }

    public function test_content_is_stored_as_plain_text_without_sanitisation_or_escaping_on_save(): void
    {
        $this->actingAs($this->admin())->post(route('admin.events.store'), $this->payload([
            'content' => 'Line one <not-a-real-tag> line two',
        ]));

        $event = EventListing::query()->firstOrFail();
        $this->assertSame('Line one <not-a-real-tag> line two', $event->content, 'Content is plain text, stored exactly as submitted — escaping happens only at render time.');
    }

    public function test_an_image_can_be_uploaded_on_create(): void
    {
        $this->actingAs($this->admin())->post(route('admin.events.store'), [
            ...$this->payload(),
            'image' => $this->pngUpload(),
        ]);

        $event = EventListing::query()->firstOrFail();
        $this->assertNotNull($event->image_path);
        Storage::disk('public')->assertExists($event->image_path);
    }

    // --- update ---------------------------------------------------------------

    public function test_an_admin_can_update_an_events_fields(): void
    {
        $event = EventListing::factory()->create(['title' => 'Old Title']);

        $this->actingAs($this->admin())->patch(route('admin.events.update', $event), $this->payload([
            'slug' => $event->slug,
            'title' => 'New Title',
        ]))->assertRedirect(route('admin.events.index'));

        $this->assertSame('New Title', $event->fresh()->title);
    }

    public function test_updating_does_not_change_status_or_published_at(): void
    {
        $event = EventListing::factory()->published()->create();
        $originalPublishedAt = $event->published_at;

        $this->actingAs($this->admin())->patch(route('admin.events.update', $event), $this->payload([
            'slug' => $event->slug,
        ]));

        $event->refresh();
        $this->assertSame('published', $event->status);
        $this->assertTrue($originalPublishedAt->equalTo($event->published_at));
    }

    public function test_duplicate_slug_is_rejected_on_update_but_keeping_its_own_slug_is_fine(): void
    {
        $other = EventListing::factory()->create(['slug' => 'taken-slug']);
        $event = EventListing::factory()->create(['slug' => 'own-slug']);

        $this->actingAs($this->admin())
            ->patch(route('admin.events.update', $event), $this->payload(['slug' => 'taken-slug']))
            ->assertSessionHasErrors('slug');

        $this->actingAs($this->admin())
            ->patch(route('admin.events.update', $event), $this->payload(['slug' => 'own-slug']))
            ->assertSessionHasNoErrors();
    }

    public function test_replacing_an_image_deletes_the_previous_file(): void
    {
        $this->actingAs($this->admin())->post(route('admin.events.store'), $this->payload(['slug' => 'temp-event', 'image' => $this->pngUpload()]));
        $event = EventListing::query()->where('slug', 'temp-event')->firstOrFail();
        $firstPath = $event->image_path;

        $this->actingAs($this->admin())->patch(route('admin.events.update', $event), [
            ...$this->payload(['slug' => 'temp-event']),
            'image' => $this->pngUpload('second.png'),
        ]);

        $event->refresh();
        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($event->image_path);
    }

    // --- publish / unpublish ----------------------------------------------------

    public function test_publishing_a_draft_stamps_published_at(): void
    {
        $event = EventListing::factory()->create();

        $this->actingAs($this->admin())->post(route('admin.events.publish', $event))->assertRedirect();

        $event->refresh();
        $this->assertSame('published', $event->status);
        $this->assertNotNull($event->published_at);
    }

    public function test_republishing_restamps_published_at(): void
    {
        $event = EventListing::factory()->published()->create(['published_at' => now()->subMonth()]);
        $original = $event->published_at;

        $this->travel(1)->hour();
        $this->actingAs($this->admin())->post(route('admin.events.publish', $event));

        $event->refresh();
        $this->assertTrue($event->published_at->greaterThan($original));
    }

    public function test_unpublishing_hides_it_from_the_public_listing(): void
    {
        $event = EventListing::factory()->published()->create(['title' => 'Was Live']);

        $this->actingAs($this->admin())->post(route('admin.events.unpublish', $event));

        $this->assertSame('draft', $event->fresh()->status);
        $this->get(route('events.index'))->assertDontSee('Was Live');
    }

    // --- delete ------------------------------------------------------------------

    public function test_an_admin_can_delete_an_event_and_its_image(): void
    {
        $this->actingAs($this->admin())->post(route('admin.events.store'), [
            ...$this->payload(),
            'image' => $this->pngUpload(),
        ]);
        $event = EventListing::query()->firstOrFail();
        $path = $event->image_path;

        $this->actingAs($this->admin())->delete(route('admin.events.destroy', $event))->assertRedirect();

        $this->assertSame(0, EventListing::query()->count());
        Storage::disk('public')->assertMissing($path);
    }
}
