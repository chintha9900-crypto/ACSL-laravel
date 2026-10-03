<?php

namespace App\Actions\Content;

use App\Models\EventListing;

/**
 * Mirrors PublishNewsItem/PublishBlogPost: `published_at` is stamped by
 * this Action, so republishing an edited event always restamps it — every
 * publish sets it to the current time, not only the first one.
 */
class PublishEventListing
{
    public function handle(EventListing $event): EventListing
    {
        $event->forceFill([
            'status' => EventListing::STATUS_PUBLISHED,
            'published_at' => now(),
        ])->save();

        return $event;
    }
}
