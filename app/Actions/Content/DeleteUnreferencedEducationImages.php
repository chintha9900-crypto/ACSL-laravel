<?php

namespace App\Actions\Content;

use App\Models\EducationArticle;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes Education inline images that no article — published or draft —
 * still references. Each path is re-checked against the database at the
 * moment of deletion, so an image shared by two articles, or re-used since,
 * is never removed. Callers run this only after their transaction commits.
 */
class DeleteUnreferencedEducationImages
{
    /**
     * @param  list<string>  $paths  Public-disk paths under EducationArticle::INLINE_IMAGE_DIRECTORY.
     * @return list<string> The paths actually deleted.
     */
    public function handle(array $paths): array
    {
        $deleted = [];

        foreach ($paths as $path) {
            if (! str_starts_with($path, EducationArticle::INLINE_IMAGE_DIRECTORY.'/') || $this->isReferenced($path)) {
                continue;
            }

            if (Storage::disk('public')->delete($path)) {
                $deleted[] = $path;
            }
        }

        return $deleted;
    }

    public function isReferenced(string $path): bool
    {
        $file = basename($path);

        return EducationArticle::query()
            ->where('content', 'like', '%'.EducationArticle::inlineImageUrlPrefix().addcslashes($file, '%_\\').'%')
            ->exists();
    }
}
