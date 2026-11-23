<?php

namespace App\Actions\Content;

use App\Exceptions\EducationArticleNotSavedException;
use App\Models\EducationArticle;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Mirrors UpdateJobListingLogo: a new featured image is stored under a
 * random name first; if the article cannot be saved with it, the new file is
 * removed again. The previous file is deleted only after the surrounding
 * transaction commits (DB::afterCommit), so a rolled-back edit never loses it.
 */
class UpdateEducationFeaturedImage
{
    private const DISK = 'public';

    /**
     * Extensions chosen from the file's detected content, never its name.
     *
     * @var list<string>
     */
    private const STORED_EXTENSIONS = ['jpg', 'png'];

    public function handle(EducationArticle $article, UploadedFile $file): string
    {
        $path = $this->store($file, EducationArticle::FEATURED_IMAGE_DIRECTORY);
        $previous = $article->featured_image_path;

        try {
            if (! $article->forceFill(['featured_image_path' => $path])->save()) {
                throw new EducationArticleNotSavedException('The article could not be updated with the new image.');
            }
        } catch (Throwable $exception) {
            Storage::disk(self::DISK)->delete($path);
            $article->featured_image_path = $previous;

            throw $exception;
        }

        if ($previous !== null && $previous !== $path) {
            DB::afterCommit(fn () => Storage::disk(self::DISK)->delete($previous));
        }

        return $path;
    }

    /**
     * Clears the featured image; the file is deleted only after commit.
     */
    public function remove(EducationArticle $article): void
    {
        $previous = $article->featured_image_path;

        if ($previous === null) {
            return;
        }

        try {
            if (! $article->forceFill(['featured_image_path' => null])->save()) {
                throw new EducationArticleNotSavedException('The article could not be updated to remove its image.');
            }
        } catch (Throwable $exception) {
            $article->featured_image_path = $previous;

            throw $exception;
        }

        DB::afterCommit(fn () => Storage::disk(self::DISK)->delete($previous));
    }

    /**
     * Stores an image under a random, server-generated name and returns its
     * public-disk path. Shared with the inline-image upload.
     */
    public function store(UploadedFile $file, string $directory): string
    {
        $extension = $file->guessExtension();
        $extension = $extension === 'jpeg' ? 'jpg' : $extension;

        if (! in_array($extension, self::STORED_EXTENSIONS, true)) {
            throw new RuntimeException('Unsupported image type.');
        }

        $path = $directory.'/'.Str::lower((string) Str::ulid()).'.'.$extension;

        $stream = fopen($file->getRealPath(), 'rb');

        try {
            $written = Storage::disk(self::DISK)->put($path, $stream, 'public');
        } finally {
            fclose($stream);
        }

        if ($written === false) {
            throw new RuntimeException('The image could not be stored.');
        }

        return $path;
    }
}
