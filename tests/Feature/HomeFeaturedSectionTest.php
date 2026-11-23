<?php

namespace Tests\Feature;

use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\EventListing;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\MysqlTestCase;

/**
 * The homepage "Featured insights & events" section (HomeController).
 */
class HomeFeaturedSectionTest extends MysqlTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        // 10:00 on 25 November in Sri Lanka (04:30 UTC).
        $this->travelTo(CarbonImmutable::parse('2026-11-25 10:00', EventListing::EVENT_TIMEZONE));
    }

    private function xpath(): DOMXPath
    {
        $document = new DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML($this->get(route('home'))->assertOk()->getContent());
        libxml_clear_errors();

        return new DOMXPath($document);
    }

    /**
     * @return list<string> Hrefs of the event rows, in display order.
     */
    private function eventLinks(): array
    {
        $links = [];
        foreach ($this->xpath()->query('//*[@data-home-events]//a[@data-home-event]') as $link) {
            $links[] = $link->getAttribute('href');
        }

        return $links;
    }

    /**
     * @param  string  $startsAt  Sri Lankan wall-clock time, as editors enter it.
     */
    private function event(string $startsAt, array $attributes = []): EventListing
    {
        return EventListing::factory()->published()->create(['starts_at' => $startsAt, ...$attributes]);
    }

    private function article(int $position, array $attributes = []): BlogPost
    {
        $post = BlogPost::factory()->published()->create($attributes);
        $post->forceFill(['homepage_position' => $position])->save();

        return $post;
    }

    // --- events column ---------------------------------------------------------------

    public function test_the_three_nearest_upcoming_events_are_shown_soonest_first(): void
    {
        $fourth = $this->event('2026-12-20 09:00');
        $second = $this->event('2026-12-01 18:30');
        $first = $this->event('2026-11-28 09:00');
        $third = $this->event('2026-12-10 09:00');

        $this->assertSame(
            [route('events.show', $first->slug), route('events.show', $second->slug), route('events.show', $third->slug)],
            $this->eventLinks(),
        );
        $this->assertNotContains(route('events.show', $fourth->slug), $this->eventLinks());
    }

    public function test_past_draft_and_not_yet_published_events_are_excluded(): void
    {
        $this->event('2026-11-24 18:00', ['title' => 'Yesterday event']);
        EventListing::factory()->create(['starts_at' => '2026-11-30 10:00', 'title' => 'Draft event']);
        EventListing::factory()->create(['starts_at' => '2026-11-30 10:00', 'status' => 'published', 'published_at' => now()->addDay(), 'title' => 'Scheduled event']);
        $upcoming = $this->event('2026-11-30 10:00', ['title' => 'Real upcoming event']);

        $this->assertSame([route('events.show', $upcoming->slug)], $this->eventLinks());
        $this->get(route('home'))->assertDontSee(['Yesterday event', 'Draft event', 'Scheduled event']);
    }

    public function test_an_event_stays_on_the_homepage_for_its_whole_day_in_colombo(): void
    {
        $morning = $this->event('2026-11-25 08:00');

        $this->assertSame([route('events.show', $morning->slug)], $this->eventLinks(), 'Started two hours ago, still upcoming today.');

        $this->travelTo(CarbonImmutable::parse('2026-11-25 23:59:59', EventListing::EVENT_TIMEZONE));
        $this->assertSame([route('events.show', $morning->slug)], $this->eventLinks());

        $this->travelTo(CarbonImmutable::parse('2026-11-26 00:00:01', EventListing::EVENT_TIMEZONE));
        $this->assertSame([], $this->eventLinks());
    }

    public function test_the_rule_uses_colombo_time_not_the_utc_clock(): void
    {
        // 20:00 local on the 25th. At 01:00 on the 26th in Colombo it is still
        // 19:30 on the 25th in UTC — a UTC comparison would wrongly keep it.
        $event = $this->event('2026-11-25 20:00');

        $this->travelTo(CarbonImmutable::parse('2026-11-26 01:00', EventListing::EVENT_TIMEZONE));
        $this->assertSame('2026-11-25 19:30', now('UTC')->format('Y-m-d H:i'));

        $this->assertSame([], $this->eventLinks());
        $this->assertFalse($event->fresh()->isUpcoming());
    }

    public function test_each_event_row_shows_its_date_time_title_and_excerpt_and_links_to_the_event(): void
    {
        $event = $this->event('2026-12-03 18:30', ['title' => 'Night Flying Talk', 'excerpt' => 'An evening with instructors.']);

        $row = $this->xpath()->query('//a[@data-home-event]')->item(0);
        $text = preg_replace('/\s+/', ' ', trim($row->textContent));

        $this->assertSame(route('events.show', $event->slug), $row->getAttribute('href'));
        $this->assertSame('Dec 3 6:30pm Night Flying Talk An evening with instructors.', $text);
        $this->get(route('events.show', $event->slug))->assertOk();
    }

    public function test_fewer_than_three_and_no_upcoming_events_are_handled(): void
    {
        $this->event('2026-11-27 09:00');
        $this->assertCount(1, $this->eventLinks());

        EventListing::query()->delete();
        $this->get(route('home'))->assertOk()->assertSee('There are no upcoming events right now.');
    }

    // --- article columns -----------------------------------------------------------------

    public function test_positions_one_and_two_appear_in_the_middle_and_right_columns(): void
    {
        $category = BlogCategory::factory()->create(['name' => 'Careers']);
        $right = $this->article(2, ['title' => 'Right column post', 'excerpt' => 'Right excerpt', 'published_at' => '2026-11-20 09:00:00']);
        $middle = $this->article(1, ['title' => 'Middle column post', 'excerpt' => 'Middle excerpt', 'blog_category_id' => $category->id, 'published_at' => '2026-11-18 09:00:00']);

        $xpath = $this->xpath();
        $columns = $xpath->query('//*[@data-featured-insights]//div[contains(@class, "grid")]/*');

        $this->assertSame(3, $columns->length);
        $this->assertTrue($columns->item(0)->hasAttribute('data-home-events'), 'Events come first (also the first item when stacked on mobile).');
        $this->assertSame('1', $columns->item(1)->getAttribute('data-home-article'));
        $this->assertSame('2', $columns->item(2)->getAttribute('data-home-article'));

        $middleText = preg_replace('/\s+/', ' ', $columns->item(1)->textContent);
        $this->assertStringContainsString('18 Nov 2026 · Careers', $middleText);
        $this->assertStringContainsString('Middle column post', $middleText);
        $this->assertStringContainsString('Middle excerpt', $middleText);
        $this->assertStringContainsString('Right column post', $columns->item(2)->textContent);
        $this->assertSame(route('blog.show', $middle->slug), $xpath->query('.//h3/a', $columns->item(1))->item(0)->getAttribute('href'));
        $this->assertSame(route('blog.show', $right->slug), $xpath->query('.//h3/a', $columns->item(2))->item(0)->getAttribute('href'));
        $this->get(route('blog.show', $middle->slug))->assertOk();
    }

    public function test_unpublished_posts_holding_a_position_are_not_shown(): void
    {
        $draft = BlogPost::factory()->create(['title' => 'Draft in slot one']);
        $draft->forceFill(['homepage_position' => 1])->save();
        $scheduled = BlogPost::factory()->create(['title' => 'Scheduled in slot two', 'status' => 'published', 'published_at' => now()->addDay()]);
        $scheduled->forceFill(['homepage_position' => 2])->save();

        $this->get(route('home'))->assertOk()->assertDontSee(['Draft in slot one', 'Scheduled in slot two']);
        $this->assertSame(0, $this->xpath()->query('//*[@data-home-article]')->length);
    }

    public function test_two_different_articles_are_shown_and_never_the_same_one_twice(): void
    {
        $first = $this->article(1);
        $second = $this->article(2);

        $slugs = [];
        foreach ($this->xpath()->query('//*[@data-home-article]//h3/a') as $link) {
            $slugs[] = $link->getAttribute('href');
        }
        $this->assertSame([route('blog.show', $first->slug), route('blog.show', $second->slug)], $slugs);

        // The database itself refuses a second post in a taken position, and a
        // post has a single position column, so it can only appear once.
        $this->expectException(QueryException::class);
        DB::table('blog_posts')->where('id', $second->id)->update(['homepage_position' => 1]);
    }

    public function test_positions_outside_one_and_two_are_refused_by_the_database(): void
    {
        $post = BlogPost::factory()->published()->create();

        $this->expectException(QueryException::class);
        DB::table('blog_posts')->where('id', $post->id)->update(['homepage_position' => 3]);
    }

    public function test_a_missing_image_and_missing_excerpt_are_handled(): void
    {
        $this->article(1, ['title' => 'No image post', 'excerpt' => null, 'content' => '<p>First words of the <strong>content</strong>.</p>', 'featured_image_path' => null]);

        $xpath = $this->xpath();
        $card = $xpath->query('//*[@data-home-article="1"]')->item(0);

        $this->assertSame(0, $xpath->query('.//img', $card)->length);
        $this->assertSame(1, $xpath->query('./a[1]//svg', $card)->length, 'The image area shows the neutral icon panel.');
        $this->assertStringContainsString('First words of the content.', $card->textContent);
    }

    public function test_the_featured_image_is_shown_when_present(): void
    {
        Storage::disk('public')->put('blog/pic.png', 'png');
        $this->article(1, ['featured_image_path' => 'blog/pic.png']);

        $this->get(route('home'))->assertSee('src="'.Storage::disk('public')->url('blog/pic.png').'"', false);
    }

    public function test_the_section_works_with_no_events_and_no_articles(): void
    {
        $response = $this->get(route('home'))->assertOk();

        $response->assertSee('Featured insights &amp; events', false);
        $response->assertSee('There are no upcoming events right now.');
        $this->assertSame(0, $this->xpath()->query('//*[@data-home-article]')->length);
    }

    /**
     * @return list<string> Each grid slot: "events", "article-N" or "placeholder-N".
     */
    private function gridSlots(): array
    {
        $slots = [];
        foreach ($this->xpath()->query('//*[@data-featured-grid]/*') as $slot) {
            $slots[] = match (true) {
                $slot->hasAttribute('data-home-events') => 'events',
                $slot->hasAttribute('data-home-article') => 'article-'.$slot->getAttribute('data-home-article'),
                default => 'placeholder-'.$slot->getAttribute('data-home-article-placeholder'),
            };
        }

        return $slots;
    }

    public function test_the_three_column_frame_is_kept_whatever_content_is_missing(): void
    {
        $grid = $this->xpath()->query('//*[@data-featured-grid]')->item(0);
        $this->assertStringContainsString('lg:grid-cols-3', $grid->getAttribute('class'));
        $this->assertSame(['events', 'placeholder-1', 'placeholder-2'], $this->gridSlots(), 'No content: the empty states stay inside their own columns.');

        $this->article(2);
        $this->assertSame(['events', 'placeholder-1', 'article-2'], $this->gridSlots(), 'Only position 2: it stays in the right column.');

        $this->article(1);
        $this->assertSame(['events', 'article-1', 'article-2'], $this->gridSlots());
    }

    public function test_each_article_ends_with_an_arrow_link_to_the_article(): void
    {
        $post = $this->article(1, ['title' => 'Arrow post']);

        $arrow = $this->xpath()->query('//*[@data-home-article="1"]//a[@data-home-article-arrow]')->item(0);

        $this->assertSame(route('blog.show', $post->slug), $arrow->getAttribute('href'));
        $this->assertSame('Read article: Arrow post', trim($arrow->textContent));
    }

    public function test_the_view_all_link_goes_to_news_and_events(): void
    {
        $link = $this->xpath()->query('//*[@data-featured-insights]//a[contains(normalize-space(.), "View all news & events")]')->item(0);

        $this->assertNotNull($link);
        $this->assertSame('View all news & events →', trim($link->textContent));
        $this->assertSame(route('news-events'), $link->getAttribute('href'));
    }

    public function test_text_is_escaped(): void
    {
        $this->event('2026-11-27 09:00', ['title' => '<script>alert(1)</script>', 'excerpt' => '<b>bold</b>']);
        $this->article(1, ['title' => '<i>Post</i>', 'excerpt' => '<img src=x onerror=alert(1)>']);

        $html = $this->get(route('home'))->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('&lt;i&gt;Post&lt;/i&gt;', $html);
    }

    public function test_the_homepage_uses_a_fixed_number_of_queries(): void
    {
        $category = BlogCategory::factory()->create();
        foreach (range(1, 5) as $day) {
            $this->event("2026-12-0{$day} 09:00");
        }
        $this->article(1, ['blog_category_id' => $category->id]);
        $this->article(2, ['blog_category_id' => $category->id]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('home'))->assertOk();
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $this->assertSame(1, $queries->filter(fn (string $sql) => str_contains($sql, 'from `event_listings`'))->count());
        $this->assertSame(1, $queries->filter(fn (string $sql) => str_contains($sql, 'from `blog_posts`'))->count());
        $this->assertSame(1, $queries->filter(fn (string $sql) => str_contains($sql, 'from `blog_categories`'))->count());
    }
}
