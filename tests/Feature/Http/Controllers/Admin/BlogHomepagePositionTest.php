<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\BlogPost;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\MysqlTestCase;

/**
 * "Featured on homepage" (homepage_position) in the Blog dashboard.
 */
class BlogHomepagePositionTest extends MysqlTestCase
{
    use CreatesReviewableApplications;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function editPayload(BlogPost $post, array $overrides = []): array
    {
        return [
            'title' => $post->title,
            'slug' => $post->slug,
            'excerpt' => (string) $post->excerpt,
            'content' => $post->content,
            'blog_category_id' => '',
            'featured_image_alt' => '',
            'is_featured' => $post->is_featured ? '1' : '0',
            'homepage_position' => (string) ($post->homepage_position ?? ''),
            ...$overrides,
        ];
    }

    private function setPosition(User $user, BlogPost $post, ?int $position): TestResponse
    {
        return $this->actingAs($user)->patch(route('admin.blog.update', $post), $this->editPayload($post->fresh(), ['homepage_position' => $position === null ? '' : (string) $position]));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function managers(): array
    {
        return ['admin' => ['admin'], 'editor' => ['editor']];
    }

    #[DataProvider('managers')]
    public function test_admin_and_editor_can_set_move_and_clear_positions(string $role): void
    {
        $user = $this->{$role}();
        $post = BlogPost::factory()->published()->create();

        $this->setPosition($user, $post, 1)->assertSessionHasNoErrors()->assertRedirect(route('admin.blog.index'));
        $this->assertSame(1, $post->fresh()->homepage_position);

        $this->setPosition($user, $post, 2)->assertSessionHasNoErrors();
        $this->assertSame(2, $post->fresh()->homepage_position);

        $this->setPosition($user, $post, null)->assertSessionHasNoErrors();
        $this->assertNull($post->fresh()->homepage_position);
    }

    #[DataProvider('managers')]
    public function test_taking_a_position_releases_it_from_the_previous_article(string $role): void
    {
        $user = $this->{$role}();
        $first = BlogPost::factory()->published()->create();
        $second = BlogPost::factory()->published()->create();
        $other = BlogPost::factory()->published()->create();
        $other->forceFill(['homepage_position' => 2])->save();

        $this->setPosition($user, $first, 1)->assertSessionHasNoErrors();
        $this->setPosition($user, $second, 1)->assertSessionHasNoErrors();

        $this->assertNull($first->fresh()->homepage_position);
        $this->assertSame(1, $second->fresh()->homepage_position);
        $this->assertSame(2, $other->fresh()->homepage_position, 'The other position is untouched.');
    }

    public function test_only_published_posts_can_be_given_a_position_and_values_are_validated(): void
    {
        $admin = $this->admin();
        $draft = BlogPost::factory()->create();
        $published = BlogPost::factory()->published()->create();

        $this->setPosition($admin, $draft, 1)->assertSessionHasErrors('homepage_position');
        $this->assertNull($draft->fresh()->homepage_position);

        foreach (['3', '0', 'top', '-1'] as $bad) {
            $this->actingAs($admin)->patch(route('admin.blog.update', $published), $this->editPayload($published, ['homepage_position' => $bad]))->assertSessionHasErrors('homepage_position');
        }
        $this->assertNull($published->fresh()->homepage_position);
    }

    public function test_members_guests_and_dev_users_cannot_change_positions(): void
    {
        $post = BlogPost::factory()->published()->create();

        $this->patch(route('admin.blog.update', $post), $this->editPayload($post, ['homepage_position' => '1']))->assertRedirect(route('login'));
        $this->setPosition($this->member(), $post, 1)->assertForbidden();
        $this->setPosition($this->dev(), $post, 1)->assertForbidden();

        $this->assertNull($post->fresh()->homepage_position);
    }

    public function test_positions_and_the_featured_post_are_independent(): void
    {
        $admin = $this->admin();
        $featured = BlogPost::factory()->published()->create(['is_featured' => true]);
        $post = BlogPost::factory()->published()->create();

        $this->setPosition($admin, $featured, 1)->assertSessionHasNoErrors();
        $this->assertTrue($featured->fresh()->is_featured, 'Setting a homepage position leaves the featured post alone.');

        $this->actingAs($admin)->patch(route('admin.blog.update', $post), $this->editPayload($post, ['is_featured' => '1']))->assertSessionHasNoErrors();
        $this->assertFalse($featured->fresh()->is_featured);
        $this->assertSame(1, $featured->fresh()->homepage_position, 'Changing the featured post leaves homepage positions alone.');
        $this->assertSame($post->id, BlogPost::forListing()->id);
    }

    public function test_saving_without_the_field_leaves_the_position_unchanged(): void
    {
        $post = BlogPost::factory()->published()->create();
        $post->forceFill(['homepage_position' => 2])->save();
        $payload = $this->editPayload($post, ['title' => 'Renamed']);
        unset($payload['homepage_position']);

        $this->actingAs($this->editor())->patch(route('admin.blog.update', $post), $payload)->assertSessionHasNoErrors();

        $this->assertSame(2, $post->fresh()->homepage_position);
        $this->assertSame('Renamed', $post->fresh()->title);
    }

    public function test_changes_are_reflected_on_the_homepage(): void
    {
        $editor = $this->editor();
        $post = BlogPost::factory()->published()->create(['title' => 'Now on the homepage']);

        $this->get(route('home'))->assertDontSee('Now on the homepage');
        $this->setPosition($editor, $post, 2);
        $this->get(route('home'))->assertSee('Now on the homepage');
        $this->setPosition($editor, $post, null);
        $this->get(route('home'))->assertDontSee('Now on the homepage');
    }

    public function test_the_form_and_list_show_the_position(): void
    {
        $admin = $this->admin();
        $post = BlogPost::factory()->published()->create(['title' => 'Positioned post']);
        $post->forceFill(['homepage_position' => 1])->save();

        $this->actingAs($admin)->get(route('admin.blog.edit', $post))->assertOk()
            ->assertSee('Featured on homepage')
            ->assertSee('<option value="1" selected>', false);
        $this->actingAs($admin)->get(route('admin.blog.index'))->assertOk()->assertSee('Home 1');
    }
}
