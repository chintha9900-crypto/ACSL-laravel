<?php

namespace App\Actions\Content;

use App\Exceptions\EducationArticleNotSavedException;
use App\Models\EducationArticle;
use App\Models\User;

/**
 * The explicit Publish action. `published_at` is restamped on every publish,
 * as PublishCsrProject.
 */
class PublishEducationArticle
{
    public function handle(EducationArticle $article, User $user): EducationArticle
    {
        $saved = $article->forceFill([
            'status' => EducationArticle::STATUS_PUBLISHED,
            'published_at' => now(),
            'updated_by_user_id' => $user->id,
        ])->save();

        if (! $saved) {
            throw new EducationArticleNotSavedException('The article could not be published.');
        }

        return $article;
    }
}
