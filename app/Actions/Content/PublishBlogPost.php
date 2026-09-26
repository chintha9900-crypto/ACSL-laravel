<?php

namespace App\Actions\Content;

use App\Models\BlogPost;

/**
 * docs/database/10_CONTENT_SCHEMA.md §3 / docs/architecture/10 §2:
 * `published_at` is stamped by an Action, fixing the legacy bug where
 * republishing an edited post never restamped it — every publish sets it to
 * the current time, not only the first one.
 */
class PublishBlogPost
{
    public function handle(BlogPost $post): BlogPost
    {
        $post->forceFill([
            'status' => BlogPost::STATUS_PUBLISHED,
            'published_at' => now(),
        ])->save();

        return $post;
    }
}
