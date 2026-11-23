<?php

namespace Tests\Feature;

use App\Models\EventListing;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Tests\MysqlTestCase;

/**
 * The public Events page: upcoming events and the Past Events Archive.
 */
class EventsArchiveTest extends MysqlTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-25 10:00', EventListing::EVENT_TIMEZONE));
    }

    private function event(string $startsAt, array $attributes = []): EventListing
    {
        return EventListing::factory()->published()->create(['starts_at' => $startsAt, ...$attributes]);
    }

    /**
     * @return array{upcoming: list<string>, past: list<string>}
     */
    private function lists(string $url = '/events'): array
    {
        $document = new DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML($this->get($url)->assertOk()->getContent());
        libxml_clear_errors();
        $xpath = new DOMXPath($document);

        $slugs = function (string $container) use ($xpath): array {
            $found = [];
            foreach ($xpath->query('//*[@'.$container.']//h2/a') as $link) {
                $found[] = basename(parse_url($link->getAttribute('href'), PHP_URL_PATH));
            }

            return $found;
        };

        return ['upcoming' => $slugs('data-events-upcoming'), 'past' => $slugs('data-events-past')];
    }

    public function test_upcoming_and_past_events_are_split_and_ordered(): void
    {
        $later = $this->event('2026-12-10 09:00');
        $today = $this->event('2026-11-25 08:00');
        $soon = $this->event('2026-11-30 09:00');
        $older = $this->event('2026-10-01 09:00');
        $recent = $this->event('2026-11-24 19:00');

        $lists = $this->lists();

        $this->assertSame([$today->slug, $soon->slug, $later->slug], $lists['upcoming'], 'Upcoming: soonest first, including today.');
        $this->assertSame([$recent->slug, $older->slug], $lists['past'], 'Archive: newest first.');
        $this->get('/events')->assertSee('Past Events Archive');
    }

    public function test_no_event_appears_in_both_lists_and_drafts_appear_in_neither(): void
    {
        foreach (['2026-11-20 10:00', '2026-11-25 00:00', '2026-11-24 23:59', '2026-11-26 10:00'] as $startsAt) {
            $this->event($startsAt);
        }
        $draftPast = EventListing::factory()->create(['starts_at' => '2026-11-01 10:00']);
        $draftFuture = EventListing::factory()->create(['starts_at' => '2026-12-01 10:00']);

        $lists = $this->lists();

        $this->assertSame([], array_intersect($lists['upcoming'], $lists['past']));
        $this->assertCount(4, [...$lists['upcoming'], ...$lists['past']]);
        $this->assertNotContains($draftPast->slug, [...$lists['upcoming'], ...$lists['past']]);
        $this->assertNotContains($draftFuture->slug, [...$lists['upcoming'], ...$lists['past']]);
    }

    public function test_an_event_moves_to_the_archive_after_its_day_ends_in_colombo(): void
    {
        $event = $this->event('2026-11-25 18:00');

        $this->travelTo(CarbonImmutable::parse('2026-11-25 23:59:59', EventListing::EVENT_TIMEZONE));
        $this->assertSame(['upcoming' => [$event->slug], 'past' => []], $this->lists());

        // Just after midnight in Colombo — still the 25th (18:30) in UTC.
        $this->travelTo(CarbonImmutable::parse('2026-11-26 00:00:01', EventListing::EVENT_TIMEZONE));
        $this->assertSame(['upcoming' => [], 'past' => [$event->slug]], $this->lists());
        $this->get('/events')->assertSee('No upcoming events.');
    }

    public function test_archived_events_keep_their_detail_pages(): void
    {
        $past = $this->event('2026-10-10 10:00', ['title' => 'Airshow visit']);

        $this->get(route('events.show', $past->slug))->assertOk()->assertSee('Airshow visit');
    }

    public function test_upcoming_and_archive_paginate_independently(): void
    {
        foreach (range(1, 10) as $i) {
            $this->event(sprintf('2026-12-%02d 09:00', $i), ['title' => "Upcoming {$i}"]);
            $this->event(sprintf('2026-10-%02d 09:00', $i), ['title' => "Past {$i}"]);
        }

        $first = $this->lists('/events');
        $this->assertCount(9, $first['upcoming']);
        $this->assertCount(9, $first['past']);

        $pastPage2 = $this->lists('/events?past_page=2');
        $this->assertSame($first['upcoming'], $pastPage2['upcoming'], 'Changing the archive page leaves the upcoming list alone.');
        $this->assertCount(1, $pastPage2['past']);
        $this->assertNotContains($pastPage2['past'][0], $first['past']);

        $upcomingPage2 = $this->lists('/events?page=2');
        $this->assertSame($first['past'], $upcomingPage2['past'], 'Changing the upcoming page leaves the archive alone.');
        $this->assertCount(1, $upcomingPage2['upcoming']);

        $html = $this->get('/events?page=2')->getContent();
        $this->assertStringContainsString('past_page=2', html_entity_decode($html));
        $this->assertStringContainsString('page=2&amp;past_page=2', $html, 'Archive links keep the upcoming page.');
    }

    public function test_the_archive_section_is_hidden_when_there_are_no_past_events(): void
    {
        $this->event('2026-12-01 10:00');

        $this->get('/events')->assertOk()->assertDontSee('Past Events Archive');
    }
}
