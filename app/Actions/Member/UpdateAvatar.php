<?php

namespace App\Actions\Member;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class UpdateAvatar
{
    /**
     * The public disk (docs/architecture/07 §1): avatars are genuinely public
     * content, never the `private` disk used for aviation proof/payment evidence.
     */
    private const DISK = 'public';

    /**
     * Extensions a stored avatar may have, chosen from its detected content —
     * never from the client's filename or reported MIME type.
     *
     * @var list<string>
     */
    private const STORED_EXTENSIONS = ['jpg', 'png'];

    /**
     * Store a new avatar under a server-generated path, point the user at it, and
     * only then remove the previous file — so a failed upload never loses the old
     * avatar, and the old file is never left behind after a successful one.
     */
    public function handle(User $user, UploadedFile $file): string
    {
        $extension = $file->guessExtension();
        $extension = $extension === 'jpeg' ? 'jpg' : $extension;

        if (! in_array($extension, self::STORED_EXTENSIONS, true)) {
            throw new RuntimeException('Unsupported avatar image type.');
        }

        $path = 'avatars/'.Str::lower((string) Str::ulid()).'.'.$extension;

        $stream = fopen($file->getRealPath(), 'rb');

        try {
            $written = Storage::disk(self::DISK)->put($path, $stream, 'public');
        } finally {
            fclose($stream);
        }

        if ($written === false) {
            throw new RuntimeException('The avatar could not be stored.');
        }

        $previous = $user->avatar_path;

        $user->forceFill(['avatar_path' => $path])->save();

        if ($previous !== null && $previous !== $path) {
            Storage::disk(self::DISK)->delete($previous);
        }

        return $path;
    }
}
