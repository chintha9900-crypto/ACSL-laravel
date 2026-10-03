<?php

namespace App\Actions\Content;

use App\Models\CsrProject;

/**
 * Mirrors UnpublishNewsItem/UnpublishBlogPost: `published_at` is left
 * untouched — it is a historical "when was this last published" stamp,
 * not a "currently live" flag; `status` alone gates public visibility
 * (CsrProject::scopePublished()).
 */
class UnpublishCsrProject
{
    public function handle(CsrProject $project): CsrProject
    {
        $project->forceFill(['status' => CsrProject::STATUS_DRAFT])->save();

        return $project;
    }
}
