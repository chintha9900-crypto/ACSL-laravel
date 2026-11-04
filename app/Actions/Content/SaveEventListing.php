<?php

namespace App\Actions\Content;

use App\Models\EventListing;
use App\Models\User;
use App\Support\HtmlSanitizer;

/**
 * Mirrors SaveNewsItem/SaveBlogPost: creates/updates an event's editable
 * fields only — title, slug, excerpt, content, starts_at, location.
 * Publishing is a separate, explicit workflow action (PublishEventListing/
 * UnpublishEventListing), never folded into a general field edit, so this
 * action never touches `status`/`published_at`. The image is handled
 * separately too (UpdateEventImage) since it is an upload, not a plain
 * field. `content` is allow-list sanitised on save, same as Blog/News.
 */
class SaveEventListing
{
    /**
     * @param  array<string, mixed>  $data  Validated: title, slug, excerpt, content, starts_at, location.
     */
    public function create(array $data, User $creator): EventListing
    {
        $data['content'] = HtmlSanitizer::clean((string) ($data['content'] ?? ''));
        $data['created_by_user_id'] = $creator->id;
        $data['status'] = EventListing::STATUS_DRAFT;

        return EventListing::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(EventListing $event, array $data): EventListing
    {
        $data['content'] = HtmlSanitizer::clean((string) ($data['content'] ?? ''));
        $event->fill($data)->save();

        return $event;
    }
}
