<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\BlogCategory;
use App\Models\BlogPost;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\Concerns\CreatesUploads;
use Tests\MysqlTestCase;

class BlogPostControllerTest extends MysqlTestCase
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
            'title' => 'A New Post',
            'slug' => 'a-new-post',
            'excerpt' => 'A short summary.',
            'content' => '<p>The body.</p>',
            'blog_category_id' => '',
            'featured_image_alt' => '',
            ...$overrides,
        ];
    }

    // --- authorization ----------------------------------------------------

    public function test_a_guest_cannot_reach_any_admin_blog_route(): void
    {
        $post = BlogPost::factory()->create();

        $this->get(route('admin.blog.index'))->assertRedirect(route('login'));
        $this->get(route('admin.blog.create'))->assertRedirect(route('login'));
        $this->post(route('admin.blog.store'), $this->payload())->assertRedirect(route('login'));
        $this->get(route('admin.blog.edit', $post))->assertRedirect(route('login'));
        $this->patch(route('admin.blog.update', $post), $this->payload())->assertRedirect(route('login'));
        $this->delete(route('admin.blog.destroy', $post))->assertRedirect(route('login'));
        $this->post(route('admin.blog.publish', $post))->assertRedirect(route('login'));
    }

    public function test_a_non_admin_member_cannot_reach_any_admin_blog_route(): void
    {
        $post = BlogPost::factory()->create();
        $member = $this->member();

        $this->actingAs($member)->get(route('admin.blog.index'))->assertForbidden();
        $this->actingAs($member)->get(route('admin.blog.create'))->assertForbidden();
        $this->actingAs($member)->post(route('admin.blog.store'), $this->payload())->assertForbidden();
        $this->actingAs($member)->get(route('admin.blog.edit', $post))->assertForbidden();
        $this->actingAs($member)->patch(route('admin.blog.update', $post), $this->payload())->assertForbidden();
        $this->actingAs($member)->delete(route('admin.blog.destroy', $post))->assertForbidden();
        $this->actingAs($member)->post(route('admin.blog.publish', $post))->assertForbidden();
    }

    /**
     * The exact three pages named for local access: /admin/blog,
     * /admin/blog/create, /admin/blog-categories.
     */
    public function test_an_admin_can_access_the_blog_dashboard_create_page_and_categories_page(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/blog')->assertOk();
        $this->actingAs($admin)->get('/admin/blog/create')->assertOk();
        $this->actingAs($admin)->get('/admin/blog-categories')->assertOk();
    }

    public function test_an_admin_can_list_posts(): void
    {
        BlogPost::factory()->create(['title' => 'Visible To Admin']);

        $this->actingAs($this->admin())
            ->get(route('admin.blog.index'))
            ->assertOk()
            ->assertSee('Visible To Admin');
    }

    // --- create / validation ----------------------------------------------

    public function test_an_admin_can_create_a_post_as_a_draft(): void
    {
        $response = $this->actingAs($this->admin())->post(route('admin.blog.store'), $this->payload());

        $response->assertRedirect(route('admin.blog.index'));
        $post = BlogPost::query()->firstOrFail();
        $this->assertSame('draft', $post->status);
        $this->assertNull($post->published_at);
        $this->assertSame('A New Post', $post->title);
    }

    public function test_the_author_is_set_to_the_creating_admin(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.blog.store'), $this->payload());

        $this->assertSame($admin->id, BlogPost::query()->firstOrFail()->author_id);
    }

    public function test_duplicate_slugs_are_rejected_on_create(): void
    {
        BlogPost::factory()->create(['slug' => 'a-new-post']);

        $this->actingAs($this->admin())
            ->post(route('admin.blog.store'), $this->payload())
            ->assertSessionHasErrors('slug');

        $this->assertSame(1, BlogPost::query()->count());
    }

    public function test_required_fields_are_validated(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.blog.store'), [])
            ->assertSessionHasErrors(['title', 'slug', 'content']);
    }

    public function test_content_is_sanitised_on_save(): void
    {
        $this->actingAs($this->admin())->post(route('admin.blog.store'), $this->payload([
            'content' => '<p>Safe</p><script>alert(1)</script>',
        ]));

        $post = BlogPost::query()->firstOrFail();
        $this->assertStringNotContainsString('<script', $post->content);
        $this->assertStringContainsString('Safe', $post->content);
    }

    public function test_a_category_can_be_assigned_to_a_post(): void
    {
        $category = BlogCategory::factory()->create();

        $this->actingAs($this->admin())->post(route('admin.blog.store'), $this->payload([
            'blog_category_id' => $category->id,
        ]));

        $this->assertSame($category->id, BlogPost::query()->firstOrFail()->blog_category_id);
    }

    public function test_a_featured_image_can_be_uploaded_on_create(): void
    {
        $this->actingAs($this->admin())->post(route('admin.blog.store'), [
            ...$this->payload(),
            'featured_image' => $this->pngUpload(),
        ]);

        $post = BlogPost::query()->firstOrFail();
        $this->assertNotNull($post->featured_image_path);
        Storage::disk('public')->assertExists($post->featured_image_path);
    }

    // --- update -------------------------------------------------------------

    public function test_an_admin_can_update_a_posts_fields(): void
    {
        $post = BlogPost::factory()->create(['title' => 'Old Title']);

        $this->actingAs($this->admin())->patch(route('admin.blog.update', $post), $this->payload([
            'slug' => $post->slug,
            'title' => 'New Title',
        ]))->assertRedirect(route('admin.blog.index'));

        $this->assertSame('New Title', $post->fresh()->title);
    }

    public function test_updating_does_not_change_status_or_published_at(): void
    {
        $post = BlogPost::factory()->published()->create();
        $originalPublishedAt = $post->published_at;

        $this->actingAs($this->admin())->patch(route('admin.blog.update', $post), $this->payload([
            'slug' => $post->slug,
        ]));

        $post->refresh();
        $this->assertSame('published', $post->status);
        $this->assertTrue($originalPublishedAt->equalTo($post->published_at));
    }

    public function test_duplicate_slug_is_rejected_on_update_but_keeping_its_own_slug_is_fine(): void
    {
        $other = BlogPost::factory()->create(['slug' => 'taken-slug']);
        $post = BlogPost::factory()->create(['slug' => 'own-slug']);

        $this->actingAs($this->admin())
            ->patch(route('admin.blog.update', $post), $this->payload(['slug' => 'taken-slug']))
            ->assertSessionHasErrors('slug');

        $this->actingAs($this->admin())
            ->patch(route('admin.blog.update', $post), $this->payload(['slug' => 'own-slug']))
            ->assertSessionHasNoErrors();
    }

    public function test_replacing_a_featured_image_deletes_the_previous_file(): void
    {
        $post = BlogPost::factory()->create();
        $this->actingAs($this->admin())->post(route('admin.blog.store'), $this->payload(['slug' => 'temp-post', 'featured_image' => $this->pngUpload()]));
        $post = BlogPost::query()->where('slug', 'temp-post')->firstOrFail();
        $firstPath = $post->featured_image_path;

        $this->actingAs($this->admin())->patch(route('admin.blog.update', $post), [
            ...$this->payload(['slug' => 'temp-post']),
            'featured_image' => $this->pngUpload('second.png'),
        ]);

        $post->refresh();
        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($post->featured_image_path);
    }

    // --- publish / unpublish ------------------------------------------------

    public function test_publishing_a_draft_stamps_published_at(): void
    {
        $post = BlogPost::factory()->create();

        $this->actingAs($this->admin())->post(route('admin.blog.publish', $post))->assertRedirect();

        $post->refresh();
        $this->assertSame('published', $post->status);
        $this->assertNotNull($post->published_at);
    }

    public function test_republishing_restamps_published_at(): void
    {
        $post = BlogPost::factory()->published()->create(['published_at' => now()->subMonth()]);
        $original = $post->published_at;

        $this->travel(1)->hour();
        $this->actingAs($this->admin())->post(route('admin.blog.publish', $post));

        $post->refresh();
        $this->assertTrue($post->published_at->greaterThan($original));
    }

    public function test_unpublishing_hides_it_from_the_public_listing(): void
    {
        $post = BlogPost::factory()->published()->create(['title' => 'Was Live']);

        $this->actingAs($this->admin())->post(route('admin.blog.unpublish', $post));

        $this->assertSame('draft', $post->fresh()->status);
        $this->get(route('blog.index'))->assertDontSee('Was Live');
    }

    // --- delete -------------------------------------------------------------

    public function test_an_admin_can_delete_a_post_and_its_featured_image(): void
    {
        $this->actingAs($this->admin())->post(route('admin.blog.store'), [
            ...$this->payload(),
            'featured_image' => $this->pngUpload(),
        ]);
        $post = BlogPost::query()->firstOrFail();
        $path = $post->featured_image_path;

        $this->actingAs($this->admin())->delete(route('admin.blog.destroy', $post))->assertRedirect();

        $this->assertSame(0, BlogPost::query()->count());
        Storage::disk('public')->assertMissing($path);
    }
}
