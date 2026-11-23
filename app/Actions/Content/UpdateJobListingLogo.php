<?php

namespace App\Actions\Content;

use App\Exceptions\JobListingNotSavedException;
use App\Models\JobListing;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Mirrors UpdateCommercialPartnerImage: the new logo is stored under a
 * random, server-generated name first; the previous file is removed only
 * after the job points at the new one. If saving the job fails (an
 * exception, or Eloquent returning false), the new file is removed again,
 * so the job never references a missing file and no orphan is left behind.
 *
 * The previous file is deleted via DB::afterCommit(): when the caller wraps
 * the whole edit in a transaction that later rolls back, the old logo —
 * still referenced by the rolled-back row — is never lost.
 */
class UpdateJobListingLogo
{
    /** Public disk — employer logos are public content. */
    private const DISK = 'public';

    /**
     * Extensions a stored logo may have, chosen from its detected content —
     * never from the client's filename or reported MIME type.
     *
     * @var list<string>
     */
    private const STORED_EXTENSIONS = ['jpg', 'png'];

    public function handle(JobListing $job, UploadedFile $file): string
    {
        $extension = $file->guessExtension();
        $extension = $extension === 'jpeg' ? 'jpg' : $extension;

        if (! in_array($extension, self::STORED_EXTENSIONS, true)) {
            throw new RuntimeException('Unsupported image type.');
        }

        $path = 'jobs/'.Str::lower((string) Str::ulid()).'.'.$extension;

        $stream = fopen($file->getRealPath(), 'rb');

        try {
            $written = Storage::disk(self::DISK)->put($path, $stream, 'public');
        } finally {
            fclose($stream);
        }

        if ($written === false) {
            throw new RuntimeException('The logo could not be stored.');
        }

        $previous = $job->company_logo;

        try {
            if (! $job->forceFill(['company_logo' => $path])->save()) {
                throw new JobListingNotSavedException('The job could not be updated with the new logo.');
            }
        } catch (Throwable $exception) {
            Storage::disk(self::DISK)->delete($path);
            $job->company_logo = $previous;

            throw $exception;
        }

        if ($previous !== null && $previous !== $path) {
            DB::afterCommit(fn () => Storage::disk(self::DISK)->delete($previous));
        }

        return $path;
    }

    /**
     * Clears the job's logo. The file is deleted only after the job has been
     * saved without it; if that save fails, both the reference and the file
     * are kept.
     */
    public function remove(JobListing $job): void
    {
        $previous = $job->company_logo;

        if ($previous === null) {
            return;
        }

        try {
            if (! $job->forceFill(['company_logo' => null])->save()) {
                throw new JobListingNotSavedException('The job could not be updated to remove its logo.');
            }
        } catch (Throwable $exception) {
            $job->company_logo = $previous;

            throw $exception;
        }

        DB::afterCommit(fn () => Storage::disk(self::DISK)->delete($previous));
    }
}
