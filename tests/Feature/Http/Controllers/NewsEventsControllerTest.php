<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\EventListing;
use App\Models\NewsItem;
use Tests\MysqlTestCase;

class NewsEventsControllerTest extends MysqlTestCase
{
    public function test_the_page_renders_with_a_news_and_an_events_column(): void
    {
        $response = $this->get(route('news-events'))->assertOk();

        $response->assertSeeInOrder(['News', 'Events']);
        $this->assertStringContainsString('lg:grid-cols-2', $response->getContent(), 'News and events must be laid out as two columns.');
    }

    public function test_it_shows_only_published_news_and_events(): void
    {
        NewsItem::factory()->published()->create(['title' => 'Published News Item']);
        NewsItem::factory()->create(['title' => 'Draft News Item']);
        EventListing::factory()->published()->create(['title' => 'Published Event']);
        EventListing::factory()->create(['title' => 'Draft Event']);

        $response = $this->get(route('news-events'))->assertOk();

        $response->assertSee('Published News Item');
        $response->assertDontSee('Draft News Item');
        $response->assertSee('Published Event');
        $response->assertDontSee('Draft Event');
    }

    public function test_a_news_item_or_event_published_in_the_future_is_not_shown_yet(): void
    {
        NewsItem::factory()->create([
            'title' => 'Future News Item',
            'status' => NewsItem::STATUS_PUBLISHED,
            'published_at' => now()->addDay(),
        ]);
        EventListing::factory()->create([
            'title' => 'Future-Published Event',
            'status' => EventListing::STATUS_PUBLISHED,
            'published_at' => now()->addDay(),
        ]);

        $response = $this->get(route('news-events'))->assertOk();

        $response->assertDontSee('Future News Item');
        $response->assertDontSee('Future-Published Event');
    }

    public function test_each_column_shows_an_empty_state_when_there_is_nothing_published(): void
    {
        $response = $this->get(route('news-events'))->assertOk();

        $response->assertSee('No news yet.');
        $response->assertSee('No upcoming events.');
    }

    public function test_each_column_links_to_its_own_full_list_and_to_its_items(): void
    {
        $news = NewsItem::factory()->published()->create();
        $event = EventListing::factory()->published()->create();

        $response = $this->get(route('news-events'))->assertOk();

        $response->assertSee('href="'.route('news.index').'"', false);
        $response->assertSee('href="'.route('events.index').'"', false);
        $response->assertSee('href="'.route('news.show', $news->slug).'"', false);
        $response->assertSee('href="'.route('events.show', $event->slug).'"', false);
    }

    /**
     * The full lists (`news.index`/`events.index`) are unaffected by this
     * landing page — they must still exist as their own separate routes.
     */
    public function test_news_and_events_remain_separate_routes(): void
    {
        $this->get(route('news.index'))->assertOk();
        $this->get(route('events.index'))->assertOk();
        $this->assertNotSame(route('news.index'), route('news-events'));
        $this->assertNotSame(route('events.index'), route('news-events'));
    }
}
