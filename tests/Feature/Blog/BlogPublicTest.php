<?php

namespace Tests\Feature\Blog;

use App\Models\BlogCategory;
use App\Models\BlogComment;
use App\Models\BlogPost;
use App\Models\BlogTag;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\MysqlTestCase;

class BlogPublicTest extends MysqlTestCase
{
    private function publishedPost(array $attributes = [], int $daysAgo = 1): BlogPost
    {
        return BlogPost::factory()->published()->create(['published_at' => now()->subDays($daysAgo), ...$attributes]);
    }

    /**
     * The page without the sidebar, whose site-wide Popular posts may list any post.
     */
    private function mainColumn(string $html): string
    {
        return substr($html, 0, strpos($html, 'aria-label="Blog sidebar"'));
    }

    // --- featured flag: no special listing --------------------------------------

    public function test_a_featured_post_is_an_ordinary_card_in_the_grid_with_no_featured_block(): void
    {
        $author = User::factory()->admin()->create(['name' => 'Ada Author']);
        $this->publishedPost(['title' => 'Newest post'], 1);
        $featured = $this->publishedPost(['title' => 'Chosen feature', 'excerpt' => 'Featured excerpt.', 'is_featured' => true, 'author_id' => $author->id], 10);

        $main = $this->mainColumn($this->get(route('blog.index'))->assertOk()->getContent());

        $this->assertStringNotContainsString('id="featured-title"', $main);
        $this->assertStringNotContainsString('>Featured<', $main);
        $this->assertSame(1, substr_count($main, '>Chosen feature<'), 'The featured post appears exactly once, in the grid.');
        $this->assertMatchesRegularExpression('#<div class="grid gap-6 md:grid-cols-2">.*>Chosen feature<.*Featured excerpt\.#s', $main);
        $this->assertStringContainsString('Ada Author', $main);
        $this->assertStringContainsString('href="'.route('blog.show', $featured->slug).'"', $main);
        $this->assertLessThan(strpos($main, '>Chosen feature<'), strpos($main, '>Newest post<'), 'Newest first: the featured flag does not change the order.');
    }

    public function test_all_published_posts_are_listed_newest_first_and_drafts_stay_hidden(): void
    {
        $this->publishedPost(['title' => 'Older'], 5);
        $this->publishedPost(['title' => 'Newest'], 1);
        BlogPost::factory()->create(['title' => 'Unpublished draft']);

        $html = $this->get(route('blog.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('id="featured-title"', $html);
        $this->assertMatchesRegularExpression('#>Newest</a>.*>Older</a>#s', $this->mainColumn($html));
        $this->assertStringNotContainsString('Unpublished draft', $html);
    }

    public function test_a_featured_draft_is_never_shown(): void
    {
        BlogPost::factory()->create(['title' => 'Featured draft', 'is_featured' => true]);
        $this->publishedPost(['title' => 'Published one']);

        $this->get(route('blog.index'))->assertDontSee('Featured draft')->assertSee('Published one');
    }

    public function test_the_database_allows_only_one_featured_post(): void
    {
        $this->publishedPost(['is_featured' => true]);

        $this->expectException(QueryException::class);
        $this->publishedPost(['is_featured' => true]);
    }

    // --- feed & pagination -----------------------------------------------------

    public function test_the_feed_shows_six_posts_per_page_with_numbered_pages(): void
    {
        foreach (range(1, 14) as $i) {
            $this->publishedPost(['title' => "Post number {$i}", 'is_featured' => $i === 3], $i);
        }

        $page1 = $this->mainColumn($this->get(route('blog.index'))->assertOk()->getContent());
        foreach (range(1, 6) as $i) {
            $this->assertStringContainsString("Post number {$i}<", $page1);
        }
        $this->assertStringNotContainsString('Post number 7<', $page1);
        $this->assertSame(6, substr_count($page1, '>View More</a>'));
        $this->assertStringContainsString('href="'.route('blog.index', ['page' => 2]).'"', $page1);

        $page2 = $this->mainColumn($this->get(route('blog.index', ['page' => 2]))->assertOk()->getContent());
        foreach (range(7, 12) as $i) {
            $this->assertStringContainsString("Post number {$i}<", $page2);
        }
        $this->assertStringNotContainsString('Post number 1<', $page2);
        $this->assertStringContainsString('href="'.route('blog.index', ['page' => 3]).'"', $page2);

        $page3 = $this->mainColumn($this->get(route('blog.index', ['page' => 3]))->assertOk()->getContent());
        $this->assertStringContainsString('Post number 13<', $page3);
        $this->assertStringContainsString('Post number 14<', $page3);
    }

    public function test_feed_cards_link_title_and_image_to_the_article_and_show_date_and_author(): void
    {
        $author = User::factory()->admin()->create(['name' => 'Bea Writer']);
        $this->publishedPost(['title' => 'Featured'], 1);
        $post = $this->publishedPost(['title' => 'Card post', 'excerpt' => 'Card excerpt.', 'author_id' => $author->id, 'featured_image_path' => 'blog/card.jpg'], 3);

        $html = $this->get(route('blog.index'))->getContent();
        $url = route('blog.show', $post->slug);

        $this->assertMatchesRegularExpression('#<a href="'.preg_quote($url, '#').'" class="block aspect-\[16/10\][^"]*"[^>]*>\s*<img src="[^"]*/storage/blog/card\.jpg"#', $html);
        $this->assertMatchesRegularExpression('#<h2[^>]*>\s*<a href="'.preg_quote($url, '#').'"[^>]*>Card post</a>#', $html);
        $this->assertStringContainsString('Card excerpt.', $html);
        $this->assertStringContainsString('Bea Writer', $html);
    }

    // --- search & filters ------------------------------------------------------

    public function test_search_matches_title_excerpt_and_content_and_is_kept_across_pages(): void
    {
        $this->publishedPost(['title' => 'Runway safety', 'content' => '<p>x</p>']);
        $this->publishedPost(['title' => 'Other', 'excerpt' => 'About the runway lights']);
        $this->publishedPost(['title' => 'Third', 'content' => '<p>Long runway story</p>']);
        $this->publishedPost(['title' => 'Unrelated helicopters']);
        foreach (range(1, 6) as $i) {
            $this->publishedPost(['title' => "Runway extra {$i}"], 20 + $i);
        }

        $response = $this->get(route('blog.index', ['q' => 'runway']))->assertOk();
        $response->assertSee('Results for')->assertDontSee('id="featured-title"', false);
        $this->assertStringNotContainsString('Unrelated helicopters', $this->mainColumn($response->getContent()));
        $response->assertSee('href="'.e(route('blog.index', ['q' => 'runway', 'page' => 2])).'"', false);

        $this->get(route('blog.index', ['q' => 'zzz-nothing']))->assertSee('No articles found.')->assertSee('Try a different search');
        $this->get(route('blog.index', ['q' => '   ']))->assertSee('Please enter a word to search for.');
        $this->get(route('blog.index', ['q' => '%']))->assertOk()->assertSee('No articles found.');
    }

    public function test_category_and_tag_filters_and_their_pagination(): void
    {
        $news = BlogCategory::factory()->create(['name' => 'News', 'slug' => 'news']);
        $tag = BlogTag::query()->create(['name' => 'Pilots', 'slug' => 'pilots']);
        $inNews = $this->publishedPost(['title' => 'In news', 'blog_category_id' => $news->id]);
        $tagged = $this->publishedPost(['title' => 'Tagged pilots']);
        $tagged->tags()->attach($tag);
        $this->publishedPost(['title' => 'Neither']);

        $byCategory = $this->mainColumn($this->get(route('blog.index', ['category' => 'news']))->getContent());
        $this->assertStringContainsString('In news', $byCategory);
        $this->assertStringNotContainsString('Tagged pilots', $byCategory);
        $this->assertStringNotContainsString('Neither', $byCategory);
        $byTag = $this->mainColumn($this->get(route('blog.index', ['tag' => 'pilots']))->getContent());
        $this->assertStringContainsString('Tagged pilots', $byTag);
        $this->assertStringNotContainsString('In news', $byTag);
        $this->assertStringContainsString('tagged', $byTag);

        foreach (range(1, 7) as $i) {
            $this->publishedPost(['title' => "News {$i}", 'blog_category_id' => $news->id], 10 + $i);
        }
        $this->get(route('blog.index', ['category' => 'news']))->assertSee('href="'.e(route('blog.index', ['category' => 'news', 'page' => 2])).'"', false);
        $this->assertNotNull($inNews);
    }

    public function test_the_sidebar_lists_search_categories_tags_and_popular_posts(): void
    {
        $category = BlogCategory::factory()->create(['name' => 'Training']);
        $tag = BlogTag::query()->create(['name' => 'Safety', 'slug' => 'safety']);
        $popular = $this->publishedPost(['title' => 'Most discussed', 'blog_category_id' => $category->id], 9);
        $popular->tags()->attach($tag);
        $member = User::factory()->active()->create();
        foreach (range(1, 3) as $i) {
            BlogComment::forceCreate(['blog_post_id' => $popular->id, 'user_id' => $member->id, 'body' => "c{$i}", 'status' => 'approved', 'moderated_at' => now()]);
        }
        BlogComment::forceCreate(['blog_post_id' => $popular->id, 'user_id' => $member->id, 'body' => 'pending', 'status' => 'pending']);
        $this->publishedPost(['title' => 'Quiet post'], 1);

        $html = $this->get(route('blog.index'))->getContent();
        $sidebar = substr($html, strpos($html, 'aria-label="Blog sidebar"'));

        $this->assertStringContainsString('role="search"', $sidebar);
        $this->assertStringContainsString('Training', $sidebar);
        $this->assertStringContainsString('Safety', $sidebar);
        $this->assertMatchesRegularExpression('/Popular posts.*Most discussed.*Quiet post/s', $sidebar);
    }

    // --- detail ----------------------------------------------------------------

    public function test_the_article_page_shows_everything_with_the_sidebar_and_related_posts(): void
    {
        $author = User::factory()->admin()->create(['name' => 'Cal Editor']);
        $category = BlogCategory::factory()->create(['name' => 'Careers', 'slug' => 'careers']);
        $tag = BlogTag::query()->create(['name' => 'ATC', 'slug' => 'atc']);
        $post = $this->publishedPost([
            'title' => 'Becoming a controller',
            'author_id' => $author->id,
            'blog_category_id' => $category->id,
            'featured_image_path' => 'blog/hero.jpg',
            'featured_image_alt' => 'Tower at dusk',
            'content' => '<h2>Training</h2><p style="text-align: center;"><strong>Bold</strong> and <em>italic</em></p><ul><li>One</li></ul>',
        ]);
        $post->tags()->attach($tag);
        $related = $this->publishedPost(['title' => 'Same category', 'blog_category_id' => $category->id], 3);
        $this->publishedPost(['title' => 'Not related'], 2);

        $this->get(route('blog.index'))->assertSee('href="'.route('blog.show', $post->slug).'"', false);

        $response = $this->get(route('blog.show', $post->slug))->assertOk();
        $response->assertSee('Becoming a controller')->assertSee('By Cal Editor')->assertSee($post->published_at->format('j M Y'))
            ->assertSee('Careers')->assertSee('ATC')->assertSee('alt="Tower at dusk"', false)->assertSee('/storage/blog/hero.jpg', false)
            ->assertSee('<h2>Training</h2>', false)->assertSee('<p style="text-align: center;"><strong>Bold</strong>', false)
            ->assertSee('aria-label="Blog sidebar"', false)
            ->assertSee('Related posts')->assertSee('href="'.route('blog.show', $related->slug).'"', false);
        $this->assertStringNotContainsString('Not related</span>', $response->getContent());
    }

    public function test_drafts_and_future_posts_are_not_public(): void
    {
        $draft = BlogPost::factory()->create(['title' => 'Draft only']);
        $future = BlogPost::factory()->published()->create(['title' => 'Future', 'published_at' => now()->addDay()]);

        $this->get(route('blog.show', $draft->slug))->assertNotFound();
        $this->get(route('blog.show', $future->slug))->assertNotFound();
        $this->get(route('blog.index', ['q' => 'Draft']))->assertDontSee('Draft only');
    }

    public function test_stored_content_is_sanitised_again_when_rendered(): void
    {
        $post = $this->publishedPost(['content' => '<p>Safe</p>']);
        DB::table('blog_posts')->where('id', $post->id)->update(['content' => '<p>Safe</p><script>alert(1)</script><p onclick="x()" style="color:red">Text</p>']);

        $html = $this->get(route('blog.show', $post->slug))->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('onclick="x()"', $html);
        $this->assertStringContainsString('<p>Text</p>', $html);
    }
}
