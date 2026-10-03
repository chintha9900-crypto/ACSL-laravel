<?php

namespace App\Actions\Content;

use App\Models\NewsItem;
use App\Models\User;
use App\Support\HtmlSanitizer;

/**
 * Mirrors SaveBlogPost: creates/updates a news item's editable fields only —
 * title, slug, excerpt, content. Publishing is a separate, explicit workflow
 * action (PublishNewsItem/UnpublishNewsItem, same "workflow buttons, no
 * arbitrary status jumps" rule as blog posts), never folded into a general
 * field edit, so this action never touches `status`/`published_at`. The
 * image is handled separately too (UpdateNewsImage) since it is an upload,
 * not a plain field.
 */
class SaveNewsItem
{
    /**
     * @param  array<string, mixed>  $data  Validated: title, slug, excerpt, content.
     */
    public function create(array $data, User $creator): NewsItem
    {
        $data['content'] = HtmlSanitizer::clean((string) ($data['content'] ?? ''));
        $data['created_by_user_id'] = $creator->id;
        $data['status'] = NewsItem::STATUS_DRAFT;

        return NewsItem::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(NewsItem $news, array $data): NewsItem
    {
        $data['content'] = HtmlSanitizer::clean((string) ($data['content'] ?? ''));

        $news->fill($data)->save();

        return $news;
    }
}
