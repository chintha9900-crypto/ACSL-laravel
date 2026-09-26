<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\NewsItem;
use DOMDocument;
use DOMXPath;
use Tests\MysqlTestCase;

class NewsControllerTest extends MysqlTestCase
{
    /**
     * Scoped to identifier-carrying attributes (href/action/value/data-*),
     * not every attribute indiscriminately — the header's decorative SVGs use
     * small numeric geometry attributes that can coincidentally equal a test
     * id without exposing anything.
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

    public function test_the_listing_shows_only_published_news(): void
    {
        NewsItem::factory()->published()->create(['title' => 'Published News']);
        NewsItem::factory()->create(['title' => 'Draft News']);

        $response = $this->get(route('news.index'))->assertOk();

        $response->assertSee('Published News');
        $response->assertDontSee('Draft News');
    }

    public function test_a_news_item_published_in_the_future_is_not_shown_yet(): void
    {
        NewsItem::factory()->create([
            'title' => 'Future News',
            'status' => NewsItem::STATUS_PUBLISHED,
            'published_at' => now()->addDay(),
        ]);

        $this->get(route('news.index'))->assertOk()->assertDontSee('Future News');
    }

    public function test_it_shows_title_excerpt_and_a_read_more_link(): void
    {
        $item = NewsItem::factory()->published()->create([
            'title' => 'Great News',
            'excerpt' => 'Something wonderful happened.',
        ]);

        $response = $this->get(route('news.index'))->assertOk();

        $response->assertSee('Great News');
        $response->assertSee('Something wonderful happened.');
        $response->assertSee('Read more');
        $response->assertSee('href="'.route('news.show', $item->slug).'"', false);
    }

    public function test_it_shows_an_empty_state_when_there_are_no_published_news_items(): void
    {
        $this->get(route('news.index'))->assertOk()->assertSee('No news yet.');
    }

    public function test_the_listing_never_exposes_a_raw_news_item_id(): void
    {
        $item = NewsItem::factory()->published()->create();

        $html = $this->get(route('news.index'))->assertOk()->getContent();

        $this->assertIdNotExposed($item->id, $html);
        $this->assertStringContainsString('href="'.route('news.show', $item->slug).'"', $html);
    }

    // --- article --------------------------------------------------------------

    public function test_a_published_news_item_is_visible_by_slug(): void
    {
        $item = NewsItem::factory()->published()->create([
            'title' => 'A Full Article',
            'content' => '<p>The full body of the article.</p>',
            'slug' => 'a-full-article',
        ]);

        $response = $this->get(route('news.show', $item->slug))->assertOk();

        $response->assertSee('A Full Article');
        $response->assertSee('The full body of the article.', false);
    }

    public function test_a_draft_news_item_404s_like_a_nonexistent_slug(): void
    {
        NewsItem::factory()->create(['slug' => 'draft-item']);

        $draftResponse = $this->get('/news/draft-item');
        $missingResponse = $this->get('/news/does-not-exist');

        $draftResponse->assertNotFound();
        $missingResponse->assertNotFound();
    }

    public function test_a_not_yet_due_news_item_404s(): void
    {
        NewsItem::factory()->create([
            'slug' => 'future-item',
            'status' => NewsItem::STATUS_PUBLISHED,
            'published_at' => now()->addWeek(),
        ]);

        $this->get('/news/future-item')->assertNotFound();
    }

    public function test_the_article_page_never_exposes_a_raw_news_item_id(): void
    {
        $item = NewsItem::factory()->published()->create();

        $html = $this->get(route('news.show', $item->slug))->assertOk()->getContent();

        $this->assertIdNotExposed($item->id, $html);
    }
}
