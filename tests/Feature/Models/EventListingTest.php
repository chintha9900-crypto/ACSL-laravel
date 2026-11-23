<?php

namespace Tests\Feature\Models;

use App\Models\EventListing;
use Carbon\CarbonImmutable;
use Tests\MysqlTestCase;

class EventListingTest extends MysqlTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-25 10:00', EventListing::EVENT_TIMEZONE));
    }

    // --- isUpcoming() ------------------------------------------------------------

    public function test_a_draft_event_with_a_future_start_is_not_upcoming(): void
    {
        $event = EventListing::factory()->create([
            'starts_at' => CarbonImmutable::parse('2026-12-01 10:00', EventListing::EVENT_TIMEZONE),
        ]);

        $this->assertSame(EventListing::STATUS_DRAFT, $event->status);
        $this->assertFalse($event->fresh()->isUpcoming());
    }

    public function test_a_published_event_with_a_future_published_at_is_not_upcoming(): void
    {
        $event = EventListing::factory()->create([
            'status' => EventListing::STATUS_PUBLISHED,
            'published_at' => CarbonImmutable::parse('2026-11-26 10:00', EventListing::EVENT_TIMEZONE)->utc(),
            'starts_at' => CarbonImmutable::parse('2026-12-01 10:00', EventListing::EVENT_TIMEZONE),
        ]);

        $this->assertFalse($event->fresh()->isUpcoming());
    }

    public function test_a_publicly_visible_event_with_a_future_start_is_upcoming(): void
    {
        $event = EventListing::factory()->published()->create([
            'starts_at' => CarbonImmutable::parse('2026-12-01 10:00', EventListing::EVENT_TIMEZONE),
        ]);

        $this->assertTrue($event->fresh()->isUpcoming());
    }

    public function test_a_publicly_visible_event_dated_before_today_in_colombo_is_not_upcoming(): void
    {
        $event = EventListing::factory()->published()->create([
            'starts_at' => CarbonImmutable::parse('2026-11-24 23:59:59', EventListing::EVENT_TIMEZONE),
        ]);

        $this->assertFalse($event->fresh()->isUpcoming());
    }
}
