<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\NewsItem;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\Concerns\CreatesUploads;
use Tests\MysqlTestCase;

class NewsControllerTest extends MysqlTestCase
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
            'title' => 'A New Announcement',
            'slug' => 'a-new-announcement',
            'excerpt' => 'A short summary.',
            'content' => '<p>The body.</p>',
            ...$overrides,
        ];
    }

    // --- authorization ------------------------------------------------------

    public function test_a_guest_cannot_reach_any_admin_news_route(): void
    {
        $news = NewsItem::factory()->create();

        $this->get(route('admin.news.index'))->assertRedirect(route('login'));
        $this->get(route('admin.news.create'))->assertRedirect(route('login'));
        $this->post(route('admin.news.store'), $this->payload())->assertRedirect(route('login'));
        $this->get(route('admin.news.show', $news))->assertRedirect(route('login'));
        $this->get(route('admin.news.edit', $news))->assertRedirect(route('login'));
        $this->patch(route('admin.news.update', $news), $this->payload())->assertRedirect(route('login'));
        $this->delete(route('admin.news.destroy', $news))->assertRedirect(route('login'));
        $this->post(route('admin.news.publish', $news))->assertRedirect(route('login'));
    }

    public function test_a_non_admin_member_cannot_reach_any_admin_news_route(): void
    {
        $news = NewsItem::factory()->create();
        $member = $this->member();

        $this->actingAs($member)->get(route('admin.news.index'))->assertForbidden();
        $this->actingAs($member)->get(route('admin.news.create'))->assertForbidden();
        $this->actingAs($member)->post(route('admin.news.store'), $this->payload())->assertForbidden();
        $this->actingAs($member)->get(route('admin.news.show', $news))->assertForbidden();
        $this->actingAs($member)->get(route('admin.news.edit', $news))->assertForbidden();
        $this->actingAs($member)->patch(route('admin.news.update', $news), $this->payload())->assertForbidden();
        $this->actingAs($member)->delete(route('admin.news.destroy', $news))->assertForbidden();
        $this->actingAs($member)->post(route('admin.news.publish', $news))->assertForbidden();
    }

    public function test_an_inactive_admin_cannot_reach_any_admin_news_route(): void
    {
        $inactiveAdmin = User::factory()->create(['role' => 'admin', 'status' => 'suspended']);

        $this->actingAs($inactiveAdmin)->get(route('admin.news.index'))->assertRedirect(route('login'));
    }

    public function test_an_admin_can_access_the_news_dashboard_and_create_page(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/news')->assertOk();
        $this->actingAs($admin)->get('/admin/news/create')->assertOk();
    }

    public function test_an_admin_can_list_news_items(): void
    {
        NewsItem::factory()->create(['title' => 'Visible To Admin']);

        $this->actingAs($this->admin())
            ->get(route('admin.news.index'))
            ->assertOk()
            ->assertSee('Visible To Admin');
    }

    public function test_an_admin_can_view_a_single_news_item(): void
    {
        $news = NewsItem::factory()->create(['title' => 'Detail Page Item']);

        $this->actingAs($this->admin())
            ->get(route('admin.news.show', $news))
            ->assertOk()
            ->assertSee('Detail Page Item');
    }

    // --- create / validation -------------------------------------------------

    public function test_an_admin_can_create_a_news_item_as_a_draft(): void
    {
        $response = $this->actingAs($this->admin())->post(route('admin.news.store'), $this->payload());

        $response->assertRedirect(route('admin.news.index'));
        $news = NewsItem::query()->firstOrFail();
        $this->assertSame('draft', $news->status);
        $this->assertNull($news->published_at);
        $this->assertSame('A New Announcement', $news->title);
    }

    public function test_the_creator_is_set_to_the_creating_admin(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.news.store'), $this->payload());

        $this->assertSame($admin->id, NewsItem::query()->firstOrFail()->created_by_user_id);
    }

    public function test_duplicate_slugs_are_rejected_on_create(): void
    {
        NewsItem::factory()->create(['slug' => 'a-new-announcement']);

        $this->actingAs($this->admin())
            ->post(route('admin.news.store'), $this->payload())
            ->assertSessionHasErrors('slug');

        $this->assertSame(1, NewsItem::query()->count());
    }

    public function test_required_fields_are_validated(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.news.store'), [])
            ->assertSessionHasErrors(['title', 'slug', 'content']);
    }

    public function test_content_is_sanitised_on_save(): void
    {
        $this->actingAs($this->admin())->post(route('admin.news.store'), $this->payload([
            'content' => '<p>Safe</p><script>alert(1)</script>',
        ]));

        $news = NewsItem::query()->firstOrFail();
        $this->assertStringNotContainsString('<script', $news->content);
        $this->assertStringContainsString('Safe', $news->content);
    }

    public function test_an_image_can_be_uploaded_on_create(): void
    {
        $this->actingAs($this->admin())->post(route('admin.news.store'), [
            ...$this->payload(),
            'image' => $this->pngUpload(),
        ]);

        $news = NewsItem::query()->firstOrFail();
        $this->assertNotNull($news->image_path);
        Storage::disk('public')->assertExists($news->image_path);
    }

    // --- update ---------------------------------------------------------------

    public function test_an_admin_can_update_a_news_items_fields(): void
    {
        $news = NewsItem::factory()->create(['title' => 'Old Title']);

        $this->actingAs($this->admin())->patch(route('admin.news.update', $news), $this->payload([
            'slug' => $news->slug,
            'title' => 'New Title',
        ]))->assertRedirect(route('admin.news.index'));

        $this->assertSame('New Title', $news->fresh()->title);
    }

    public function test_updating_does_not_change_status_or_published_at(): void
    {
        $news = NewsItem::factory()->published()->create();
        $originalPublishedAt = $news->published_at;

        $this->actingAs($this->admin())->patch(route('admin.news.update', $news), $this->payload([
            'slug' => $news->slug,
        ]));

        $news->refresh();
        $this->assertSame('published', $news->status);
        $this->assertTrue($originalPublishedAt->equalTo($news->published_at));
    }

    public function test_duplicate_slug_is_rejected_on_update_but_keeping_its_own_slug_is_fine(): void
    {
        $other = NewsItem::factory()->create(['slug' => 'taken-slug']);
        $news = NewsItem::factory()->create(['slug' => 'own-slug']);

        $this->actingAs($this->admin())
            ->patch(route('admin.news.update', $news), $this->payload(['slug' => 'taken-slug']))
            ->assertSessionHasErrors('slug');

        $this->actingAs($this->admin())
            ->patch(route('admin.news.update', $news), $this->payload(['slug' => 'own-slug']))
            ->assertSessionHasNoErrors();
    }

    public function test_replacing_an_image_deletes_the_previous_file(): void
    {
        $this->actingAs($this->admin())->post(route('admin.news.store'), $this->payload(['slug' => 'temp-news', 'image' => $this->pngUpload()]));
        $news = NewsItem::query()->where('slug', 'temp-news')->firstOrFail();
        $firstPath = $news->image_path;

        $this->actingAs($this->admin())->patch(route('admin.news.update', $news), [
            ...$this->payload(['slug' => 'temp-news']),
            'image' => $this->pngUpload('second.png'),
        ]);

        $news->refresh();
        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($news->image_path);
    }

    // --- publish / unpublish ----------------------------------------------------

    public function test_publishing_a_draft_stamps_published_at(): void
    {
        $news = NewsItem::factory()->create();

        $this->actingAs($this->admin())->post(route('admin.news.publish', $news))->assertRedirect();

        $news->refresh();
        $this->assertSame('published', $news->status);
        $this->assertNotNull($news->published_at);
    }

    public function test_republishing_restamps_published_at(): void
    {
        $news = NewsItem::factory()->published()->create(['published_at' => now()->subMonth()]);
        $original = $news->published_at;

        $this->travel(1)->hour();
        $this->actingAs($this->admin())->post(route('admin.news.publish', $news));

        $news->refresh();
        $this->assertTrue($news->published_at->greaterThan($original));
    }

    public function test_unpublishing_hides_it_from_the_public_listing(): void
    {
        $news = NewsItem::factory()->published()->create(['title' => 'Was Live']);

        $this->actingAs($this->admin())->post(route('admin.news.unpublish', $news));

        $this->assertSame('draft', $news->fresh()->status);
        $this->get(route('news.index'))->assertDontSee('Was Live');
    }

    // --- delete ------------------------------------------------------------------

    public function test_an_admin_can_delete_a_news_item_and_its_image(): void
    {
        $this->actingAs($this->admin())->post(route('admin.news.store'), [
            ...$this->payload(),
            'image' => $this->pngUpload(),
        ]);
        $news = NewsItem::query()->firstOrFail();
        $path = $news->image_path;

        $this->actingAs($this->admin())->delete(route('admin.news.destroy', $news))->assertRedirect();

        $this->assertSame(0, NewsItem::query()->count());
        Storage::disk('public')->assertMissing($path);
    }
}
