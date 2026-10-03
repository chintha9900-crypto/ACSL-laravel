<?php

namespace App\Actions\Content;

use App\Models\CsrProject;
use App\Models\User;
use App\Support\HtmlSanitizer;

/**
 * Mirrors SaveNewsItem/SaveBlogPost: creates/updates a project's editable
 * fields only — title, slug, content. Publishing is a separate, explicit
 * workflow action (PublishCsrProject/UnpublishCsrProject), never folded
 * into a general field edit, so this action never touches `status`/
 * `published_at`. The image is handled separately too (UpdateCsrImage)
 * since it is an upload, not a plain field.
 *
 * `content` IS run through HtmlSanitizer here, same as Blog/News — checked
 * first, not assumed: CsrProject::class's own docblock documents it as
 * "sanitised HTML, the same convention as those models" and both public
 * CSR views render it with `{!! !!}`, confirming this is the Blog/News
 * rule, not the Events plain-text exception.
 */
class SaveCsrProject
{
    /**
     * @param  array<string, mixed>  $data  Validated: title, slug, content.
     */
    public function create(array $data, User $creator): CsrProject
    {
        $data['content'] = HtmlSanitizer::clean((string) ($data['content'] ?? ''));
        $data['created_by_user_id'] = $creator->id;
        $data['status'] = CsrProject::STATUS_DRAFT;

        return CsrProject::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(CsrProject $project, array $data): CsrProject
    {
        $data['content'] = HtmlSanitizer::clean((string) ($data['content'] ?? ''));

        $project->fill($data)->save();

        return $project;
    }
}
