<?php

namespace App\Actions\Content;

use App\Models\BlogPost;
use App\Models\User;
use App\Support\HtmlSanitizer;

/**
 * Creates/updates a post's editable fields only — title, slug, excerpt,
 * content, category. Publishing is a separate, explicit workflow action
 * (PublishBlogPost/UnpublishBlogPost, docs/frontend/05 §1: "workflow
 * buttons... no arbitrary status jumps"), never folded into a general field
 * edit, so this action never touches `status`/`published_at`. The featured
 * image is handled separately too (UpdateBlogFeaturedImage) since it is an
 * upload, not a plain field.
 */
class SaveBlogPost
{
    /**
     * @param  array<string, mixed>  $data  Validated: title, slug, excerpt, content, blog_category_id, featured_image_alt.
     */
    public function create(array $data, User $author): BlogPost
    {
        $data['content'] = HtmlSanitizer::clean((string) ($data['content'] ?? ''));
        $data['author_id'] = $author->id;
        $data['status'] = BlogPost::STATUS_DRAFT;

        return BlogPost::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(BlogPost $post, array $data): BlogPost
    {
        $data['content'] = HtmlSanitizer::clean((string) ($data['content'] ?? ''));

        $post->fill($data)->save();

        return $post;
    }
}
