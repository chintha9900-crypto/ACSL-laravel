<?php

namespace App\Actions\Content;

use App\Models\EventListing;

/**
 * Mirrors UnpublishNewsItem/UnpublishBlogPost: `published_at` is left
 * untouched — it is a historical "when was this last published" stamp, not
 * a "currently live" flag; `status` alone gates public visibility
 * (EventListing::scopePublished()).
 */
class UnpublishEventListing
{
    public function handle(EventListing $event): EventListing
    {
        $event->forceFill(['status' => EventListing::STATUS_DRAFT])->save();

        return $event;
    }
}
