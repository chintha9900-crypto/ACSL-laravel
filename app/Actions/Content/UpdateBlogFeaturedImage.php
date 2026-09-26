<?php

namespace App\Actions\Content;

use App\Models\BlogPost;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Mirrors App\Actions\Member\UpdateAvatar: store the new file under a
 * server-generated path first, then only remove the previous one once the
 * post points at the new path — a failed upload never loses the old image,
 * and a successful one never leaves the old file behind.
 */
class UpdateBlogFeaturedImage
{
    /**
     * Public disk (docs/database/10 §5: "public images... never in
     * documents") — featured images are genuinely public content.
     */
    private const DISK = 'public';

    /**
     * Extensions a stored image may have, chosen from its detected content —
     * never from the client's filename or reported MIME type.
     *
     * @var list<string>
     */
    private const STORED_EXTENSIONS = ['jpg', 'png'];

    public function handle(BlogPost $post, UploadedFile $file): string
    {
        $extension = $file->guessExtension();
        $extension = $extension === 'jpeg' ? 'jpg' : $extension;

        if (! in_array($extension, self::STORED_EXTENSIONS, true)) {
            throw new RuntimeException('Unsupported featured image type.');
        }

        $path = 'blog/'.Str::lower((string) Str::ulid()).'.'.$extension;

        $stream = fopen($file->getRealPath(), 'rb');

        try {
            $written = Storage::disk(self::DISK)->put($path, $stream, 'public');
        } finally {
            fclose($stream);
        }

        if ($written === false) {
            throw new RuntimeException('The featured image could not be stored.');
        }

        $previous = $post->featured_image_path;

        $post->forceFill(['featured_image_path' => $path])->save();

        if ($previous !== null && $previous !== $path) {
            Storage::disk(self::DISK)->delete($previous);
        }

        return $path;
    }
}
