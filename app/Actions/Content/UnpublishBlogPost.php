<?php

namespace App\Actions\Content;

use App\Models\BlogPost;

/**
 * `published_at` is left untouched — it is a historical "when was this last
 * published" stamp, not a "currently live" flag; `status` alone gates public
 * visibility (BlogPost::scopePublished()).
 */
class UnpublishBlogPost
{
    public function handle(BlogPost $post): BlogPost
    {
        $post->forceFill(['status' => BlogPost::STATUS_DRAFT])->save();

        return $post;
    }
}
