<?php

namespace App\Actions\Content;

use App\Models\NewsItem;

/**
 * Mirrors UnpublishBlogPost: `published_at` is left untouched — it is a
 * historical "when was this last published" stamp, not a "currently live"
 * flag; `status` alone gates public visibility (NewsItem::scopePublished()).
 */
class UnpublishNewsItem
{
    public function handle(NewsItem $news): NewsItem
    {
        $news->forceFill(['status' => NewsItem::STATUS_DRAFT])->save();

        return $news;
    }
}
