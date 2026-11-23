<?php

namespace App\Console\Commands;

use App\Actions\Content\DeleteUnreferencedEducationImages;
use App\Models\EducationArticle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Removes Education inline images that no article — published or draft —
 * references, e.g. images uploaded into a form that was then abandoned.
 * Files modified within the last 24 hours are always kept, so an article
 * still being written never loses an image. Only the inline-image folder is
 * scanned; featured images are never touched here. Idempotent.
 */
class PruneEducationInlineImages extends Command
{
    protected $signature = 'education:prune-inline-images';

    protected $description = 'Delete Education inline images older than 24 hours that no article uses';

    public function handle(DeleteUnreferencedEducationImages $deleteImages): int
    {
        $disk = Storage::disk('public');
        $cutoff = now()->subDay()->getTimestamp();

        $candidates = array_values(array_filter(
            $disk->files(EducationArticle::INLINE_IMAGE_DIRECTORY),
            fn (string $path) => $disk->lastModified($path) < $cutoff,
        ));

        $deleted = $deleteImages->handle($candidates);

        $this->info('Deleted '.count($deleted).' unused Education image(s).');

        return self::SUCCESS;
    }
}
