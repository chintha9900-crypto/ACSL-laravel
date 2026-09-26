<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\BlogCategory;
use App\Models\BlogPost;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\MysqlTestCase;

class BlogCategoryControllerTest extends MysqlTestCase
{
    use CreatesReviewableApplications;

    public function test_a_guest_cannot_reach_any_admin_blog_category_route(): void
    {
        $category = BlogCategory::factory()->create();

        $this->get(route('admin.blog-categories.index'))->assertRedirect(route('login'));
        $this->post(route('admin.blog-categories.store'), ['name' => 'X', 'slug' => 'x'])->assertRedirect(route('login'));
        $this->patch(route('admin.blog-categories.update', $category), ['name' => 'X', 'slug' => 'x'])->assertRedirect(route('login'));
        $this->delete(route('admin.blog-categories.destroy', $category))->assertRedirect(route('login'));
    }

    public function test_a_non_admin_member_cannot_manage_categories(): void
    {
        $category = BlogCategory::factory()->create();
        $member = $this->member();

        $this->actingAs($member)->get(route('admin.blog-categories.index'))->assertForbidden();
        $this->actingAs($member)->post(route('admin.blog-categories.store'), ['name' => 'X', 'slug' => 'x'])->assertForbidden();
        $this->actingAs($member)->delete(route('admin.blog-categories.destroy', $category))->assertForbidden();
    }

    public function test_an_admin_can_add_a_category(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.blog-categories.store'), ['name' => 'Announcements', 'slug' => 'announcements'])
            ->assertRedirect(route('admin.blog-categories.index'));

        $this->assertSame(1, BlogCategory::query()->where('slug', 'announcements')->count());
    }

    public function test_duplicate_category_slugs_are_rejected(): void
    {
        BlogCategory::factory()->create(['slug' => 'news']);

        $this->actingAs($this->admin())
            ->post(route('admin.blog-categories.store'), ['name' => 'News Again', 'slug' => 'news'])
            ->assertSessionHasErrors('slug');

        $this->assertSame(1, BlogCategory::query()->count());
    }

    public function test_an_admin_can_edit_a_category(): void
    {
        $category = BlogCategory::factory()->create(['name' => 'Old Name', 'slug' => 'old-slug']);

        $this->actingAs($this->admin())
            ->patch(route('admin.blog-categories.update', $category), ['name' => 'New Name', 'slug' => 'old-slug'])
            ->assertRedirect(route('admin.blog-categories.index'));

        $this->assertSame('New Name', $category->fresh()->name);
    }

    public function test_keeping_a_categorys_own_slug_on_update_is_not_a_duplicate(): void
    {
        $category = BlogCategory::factory()->create(['slug' => 'own-slug']);

        $this->actingAs($this->admin())
            ->patch(route('admin.blog-categories.update', $category), ['name' => $category->name, 'slug' => 'own-slug'])
            ->assertSessionHasNoErrors();
    }

    public function test_deleting_a_category_detaches_its_posts_instead_of_deleting_them(): void
    {
        $category = BlogCategory::factory()->create();
        $post = BlogPost::factory()->create(['blog_category_id' => $category->id]);

        $this->actingAs($this->admin())
            ->delete(route('admin.blog-categories.destroy', $category))
            ->assertRedirect(route('admin.blog-categories.index'));

        $this->assertSame(0, BlogCategory::query()->count());
        $this->assertSame(1, BlogPost::query()->count());
        $this->assertNull($post->fresh()->blog_category_id);
    }
}
