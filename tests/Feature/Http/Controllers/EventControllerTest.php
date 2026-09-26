<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\EventListing;
use DOMDocument;
use DOMXPath;
use Tests\MysqlTestCase;

class EventControllerTest extends MysqlTestCase
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

    public function test_the_listing_shows_only_published_events(): void
    {
        EventListing::factory()->published()->create(['title' => 'Published Event']);
        EventListing::factory()->create(['title' => 'Draft Event']);

        $response = $this->get(route('events.index'))->assertOk();

        $response->assertSee('Published Event');
        $response->assertDontSee('Draft Event');
    }

    public function test_an_event_published_in_the_future_is_not_shown_yet(): void
    {
        EventListing::factory()->create([
            'title' => 'Future-Published Event',
            'status' => EventListing::STATUS_PUBLISHED,
            'published_at' => now()->addDay(),
        ]);

        $this->get(route('events.index'))->assertOk()->assertDontSee('Future-Published Event');
    }

    /**
     * Whether to hide events whose `starts_at` is already in the past is an
     * explicitly unresolved, unconfirmed rule (docs/database/10 §7) — this
     * stage does not invent it, so a published-but-past event still shows.
     */
    public function test_a_published_event_that_has_already_happened_still_shows(): void
    {
        EventListing::factory()->published()->create([
            'title' => 'A Past Event',
            'starts_at' => now()->subMonth(),
        ]);

        $this->get(route('events.index'))->assertOk()->assertSee('A Past Event');
    }

    public function test_it_shows_title_excerpt_date_location_and_a_read_more_link(): void
    {
        $event = EventListing::factory()->published()->create([
            'title' => 'Annual Fly-In',
            'excerpt' => 'A gathering for aviation enthusiasts.',
            'starts_at' => '2027-06-15 09:30:00',
            'location' => 'Colombo',
        ]);

        $response = $this->get(route('events.index'))->assertOk();

        $response->assertSee('Annual Fly-In');
        $response->assertSee('A gathering for aviation enthusiasts.');
        $response->assertSee('Colombo');
        $response->assertSee('15 Jun 2027');
        $response->assertSee('Read more');
        $response->assertSee('href="'.route('events.show', $event->slug).'"', false);
    }

    public function test_it_shows_an_empty_state_when_there_are_no_published_events(): void
    {
        $this->get(route('events.index'))->assertOk()->assertSee('No upcoming events.');
    }

    public function test_the_listing_never_exposes_a_raw_event_id(): void
    {
        $event = EventListing::factory()->published()->create();

        $html = $this->get(route('events.index'))->assertOk()->getContent();

        $this->assertIdNotExposed($event->id, $html);
        $this->assertStringContainsString('href="'.route('events.show', $event->slug).'"', $html);
    }

    // --- article --------------------------------------------------------------

    public function test_a_published_event_is_visible_by_slug(): void
    {
        $event = EventListing::factory()->published()->create([
            'title' => 'A Full Event',
            'content' => 'The full body of the event.',
            'location' => 'Galle Face',
            'slug' => 'a-full-event',
        ]);

        $response = $this->get(route('events.show', $event->slug))->assertOk();

        $response->assertSee('A Full Event');
        $response->assertSee('The full body of the event.');
        $response->assertSee('Galle Face');
    }

    /**
     * The reference app rendered event content as plain text — an angle
     * bracket in stored content must show up literally, not be parsed as a
     * tag (docs/architecture/10 §4, OD #19).
     */
    public function test_event_content_is_rendered_as_plain_text_not_html(): void
    {
        $event = EventListing::factory()->published()->create([
            'content' => 'Line one <b>not bold</b>.',
        ]);

        $response = $this->get(route('events.show', $event->slug))->assertOk();

        $response->assertSee('Line one <b>not bold</b>.');
        $response->assertDontSee('<b>not bold</b>', false);
    }

    public function test_a_draft_event_404s_like_a_nonexistent_slug(): void
    {
        EventListing::factory()->create(['slug' => 'draft-event']);

        $draftResponse = $this->get('/events/draft-event');
        $missingResponse = $this->get('/events/does-not-exist');

        $draftResponse->assertNotFound();
        $missingResponse->assertNotFound();
    }

    public function test_a_not_yet_due_event_404s(): void
    {
        EventListing::factory()->create([
            'slug' => 'future-event',
            'status' => EventListing::STATUS_PUBLISHED,
            'published_at' => now()->addWeek(),
        ]);

        $this->get('/events/future-event')->assertNotFound();
    }

    public function test_the_article_page_never_exposes_a_raw_event_id(): void
    {
        $event = EventListing::factory()->published()->create();

        $html = $this->get(route('events.show', $event->slug))->assertOk()->getContent();

        $this->assertIdNotExposed($event->id, $html);
    }
}
