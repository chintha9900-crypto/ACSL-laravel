<?php

namespace App\Actions\Content;

use App\Exceptions\EducationArticleNotSavedException;
use App\Models\EducationArticle;
use App\Models\User;

/**
 * Returns a published article to draft, keeping its content and images.
 * `published_at` stays as a "last published" stamp (as UnpublishCsrProject).
 */
class UnpublishEducationArticle
{
    public function handle(EducationArticle $article, User $user): EducationArticle
    {
        $saved = $article->forceFill([
            'status' => EducationArticle::STATUS_DRAFT,
            'updated_by_user_id' => $user->id,
        ])->save();

        if (! $saved) {
            throw new EducationArticleNotSavedException('The article could not be unpublished.');
        }

        return $article;
    }
}
