<?php

namespace App\Actions\Content;

use App\Models\CsrProject;

/**
 * Mirrors PublishNewsItem/PublishBlogPost: `published_at` is stamped by
 * this Action, so republishing an edited project always restamps it —
 * every publish sets it to the current time, not only the first one.
 */
class PublishCsrProject
{
    public function handle(CsrProject $project): CsrProject
    {
        $project->forceFill([
            'status' => CsrProject::STATUS_PUBLISHED,
            'published_at' => now(),
        ])->save();

        return $project;
    }
}
