<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\BlogCategory;
use App\Models\BlogPost;
use DOMDocument;
use DOMXPath;
use Tests\MysqlTestCase;

class BlogControllerTest extends MysqlTestCase
{
    /**
     * Scoped to identifier-carrying attributes (href/action/value/data-*),
     * not every attribute indiscriminately — the header's decorative SVGs use
     * small numeric geometry attributes (e.g. `y1="12"`) that can
     * coincidentally equal a test id without exposing anything.
     */
    private function assertIdNotExposed(int $id, string $html): void
    {
        $needle = (string) $id;

        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);

        foreach ($xpath->query('//@*[name()="href" or name()="action" or name()="value" or starts-with(name(), "data-")]') as $attribute) {
            $this->assertNotSame($needle, trim((string) $attribute->nodeValue), "The [{$attribute->nodeName}] attribute must not expose the raw id {$id}.");
        }
    }

    // --- listing ------------------------------------------------------------

    public function test_the_listing_shows_only_published_posts(): void
    {
        BlogPost::factory()->published()->create(['title' => 'Published Post']);
        BlogPost::factory()->create(['title' => 'Draft Post']);

        $response = $this->get(route('blog.index'))->assertOk();

        $response->assertSee('Published Post');
        $response->assertDontSee('Draft Post');
    }

    public function test_a_post_published_in_the_future_is_not_shown_yet(): void
    {
        BlogPost::factory()->create([
            'title' => 'Future Post',
            'status' => BlogPost::STATUS_PUBLISHED,
            'published_at' => now()->addDay(),
        ]);

        $this->get(route('blog.index'))->assertOk()->assertDontSee('Future Post');
    }

    public function test_it_shows_title_excerpt_category_and_a_view_more_button(): void
    {
        $category = BlogCategory::factory()->create(['name' => 'Announcements']);
        $post = BlogPost::factory()->published()->create([
            'title' => 'Great News',
            'excerpt' => 'Something wonderful happened.',
            'blog_category_id' => $category->id,
        ]);

        $response = $this->get(route('blog.index'))->assertOk();

        $response->assertSee('Great News');
        $response->assertSee('Something wonderful happened.');
        $response->assertSee('Announcements');
        $response->assertSee('View More');
        $response->assertSeeInOrder(['Announcements', 'Great News', 'Something wonderful happened.', 'View More']);
        $response->assertSee('href="'.route('blog.show', $post->slug).'"', false);
    }

    public function test_the_card_grid_uses_the_documented_responsive_columns(): void
    {
        BlogPost::factory()->published()->create();

        $html = $this->get(route('blog.index'))->assertOk()->getContent();

        $this->assertStringContainsString('md:grid-cols-2', $html);
        $this->assertStringContainsString('lg:grid-cols-3', $html);
    }

    public function test_it_shows_an_empty_state_when_there_are_no_published_posts(): void
    {
        $this->get(route('blog.index'))->assertOk()->assertSee('No articles found.');
    }

    public function test_category_filtering_shows_only_that_categorys_posts(): void
    {
        $news = BlogCategory::factory()->create(['name' => 'News', 'slug' => 'news']);
        $events = BlogCategory::factory()->create(['name' => 'Events', 'slug' => 'events']);
        BlogPost::factory()->published()->create(['title' => 'A News Post', 'blog_category_id' => $news->id]);
        BlogPost::factory()->published()->create(['title' => 'An Events Post', 'blog_category_id' => $events->id]);

        $response = $this->get(route('blog.index', ['category' => 'news']))->assertOk();

        $response->assertSee('A News Post');
        $response->assertDontSee('An Events Post');
    }

    public function test_the_listing_never_exposes_a_raw_post_id(): void
    {
        $post = BlogPost::factory()->published()->create();

        $html = $this->get(route('blog.index'))->assertOk()->getContent();

        $this->assertIdNotExposed($post->id, $html);
        $this->assertStringContainsString('href="'.route('blog.show', $post->slug).'"', $html);
    }

    // --- article --------------------------------------------------------------

    public function test_a_published_article_is_visible_by_slug(): void
    {
        $post = BlogPost::factory()->published()->create([
            'title' => 'A Full Article',
            'content' => '<p>The full body of the article.</p>',
            'slug' => 'a-full-article',
        ]);

        $response = $this->get(route('blog.show', $post->slug))->assertOk();

        $response->assertSee('A Full Article');
        $response->assertSee('The full body of the article.', false);
    }

    public function test_a_draft_article_404s_like_a_nonexistent_slug(): void
    {
        BlogPost::factory()->create(['slug' => 'draft-article']);

        $draftResponse = $this->get('/blog/draft-article');
        $missingResponse = $this->get('/blog/does-not-exist');

        $draftResponse->assertNotFound();
        $missingResponse->assertNotFound();
    }

    public function test_a_not_yet_due_article_404s(): void
    {
        BlogPost::factory()->create([
            'slug' => 'future-article',
            'status' => BlogPost::STATUS_PUBLISHED,
            'published_at' => now()->addWeek(),
        ]);

        $this->get('/blog/future-article')->assertNotFound();
    }

    public function test_the_article_page_never_exposes_a_raw_post_id(): void
    {
        $post = BlogPost::factory()->published()->create();

        $html = $this->get(route('blog.show', $post->slug))->assertOk()->getContent();

        $this->assertIdNotExposed($post->id, $html);
    }
}
