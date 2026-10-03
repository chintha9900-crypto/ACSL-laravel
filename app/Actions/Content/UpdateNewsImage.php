<?php

namespace App\Actions\Content;

use App\Models\NewsItem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Mirrors UpdateBlogFeaturedImage: store the new file under a
 * server-generated path first, then only remove the previous one once the
 * news item points at the new path — a failed upload never loses the old
 * image, and a successful one never leaves the old file behind.
 */
class UpdateNewsImage
{
    /**
     * Public disk — news images are public content, same convention as the
     * blog featured image.
     */
    private const DISK = 'public';

    /**
     * Extensions a stored image may have, chosen from its detected content —
     * never from the client's filename or reported MIME type.
     *
     * @var list<string>
     */
    private const STORED_EXTENSIONS = ['jpg', 'png'];

    public function handle(NewsItem $news, UploadedFile $file): string
    {
        $extension = $file->guessExtension();
        $extension = $extension === 'jpeg' ? 'jpg' : $extension;

        if (! in_array($extension, self::STORED_EXTENSIONS, true)) {
            throw new RuntimeException('Unsupported image type.');
        }

        $path = 'news/'.Str::lower((string) Str::ulid()).'.'.$extension;

        $stream = fopen($file->getRealPath(), 'rb');

        try {
            $written = Storage::disk(self::DISK)->put($path, $stream, 'public');
        } finally {
            fclose($stream);
        }

        if ($written === false) {
            throw new RuntimeException('The image could not be stored.');
        }

        $previous = $news->image_path;

        $news->forceFill(['image_path' => $path])->save();

        if ($previous !== null && $previous !== $path) {
            Storage::disk(self::DISK)->delete($previous);
        }

        return $path;
    }
}
