<?php

namespace App\Actions\Content;

use App\Models\EventListing;
use App\Models\User;

/**
 * Mirrors SaveNewsItem/SaveBlogPost: creates/updates an event's editable
 * fields only — title, slug, excerpt, content, starts_at, location.
 * Publishing is a separate, explicit workflow action (PublishEventListing/
 * UnpublishEventListing), never folded into a general field edit, so this
 * action never touches `status`/`published_at`. The image is handled
 * separately too (UpdateEventImage) since it is an upload, not a plain
 * field.
 *
 * Unlike BlogPost/NewsItem, `content` here is NOT run through
 * HtmlSanitizer — EventListing::class's own docblock documents that its
 * `content` is rendered as plain text (`{{ }}`, not `{!! !!}`, in
 * events/show.blade.php), an explicit, already-settled design decision this
 * CRUD preserves rather than second-guesses.
 */
class SaveEventListing
{
    /**
     * @param  array<string, mixed>  $data  Validated: title, slug, excerpt, content, starts_at, location.
     */
    public function create(array $data, User $creator): EventListing
    {
        $data['created_by_user_id'] = $creator->id;
        $data['status'] = EventListing::STATUS_DRAFT;

        return EventListing::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(EventListing $event, array $data): EventListing
    {
        $event->fill($data)->save();

        return $event;
    }
}
