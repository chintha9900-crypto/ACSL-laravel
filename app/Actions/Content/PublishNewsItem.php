<?php

namespace App\Actions\Content;

use App\Models\NewsItem;

/**
 * Mirrors PublishBlogPost: `published_at` is stamped by this Action, so
 * republishing an edited item always restamps it — every publish sets it to
 * the current time, not only the first one.
 */
class PublishNewsItem
{
    public function handle(NewsItem $news): NewsItem
    {
        $news->forceFill([
            'status' => NewsItem::STATUS_PUBLISHED,
            'published_at' => now(),
        ])->save();

        return $news;
    }
}
