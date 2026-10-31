<?php

namespace App\Actions\SchoolClub;

use App\Models\Document;
use App\Models\SchoolClubStudentBatch;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class StoreStudentBatchDocument
{
    /**
     * Store an upload for a student batch on the private disk (never public)
     * and record it as a Document owned by the batch, visible only to the
     * representative who uploaded it and to staff allowed to review it.
     *
     * Call inside the caller's transaction. `$storedPath` is set as soon as
     * the file is written, so the caller can delete it if the transaction
     * fails.
     */
    public function handle(SchoolClubStudentBatch $batch, User $uploader, UploadedFile $file, string $kind, string $extension, ?string &$storedPath): Document
    {
        $path = 'school-club-students/'.$batch->public_id.'/'.Str::lower((string) Str::ulid()).'.'.$extension;
        $stream = fopen($file->getRealPath(), 'rb');
        try {
            $written = Storage::disk(Document::DISK_PRIVATE)->put($path, $stream, 'private');
        } finally {
            fclose($stream);
        }
        if ($written === false) {
            throw new RuntimeException('The uploaded file could not be stored.');
        }
        $storedPath = $path;

        $document = new Document([
            'kind' => $kind,
            'school_club_student_batch_id' => $batch->id,
            'uploaded_by_user_id' => $uploader->id,
            'disk' => Document::DISK_PRIVATE,
            'storage_path' => $path,
            'original_filename' => Str::limit(basename(str_replace('\\', '/', $file->getClientOriginalName())) ?: 'student-list.'.$extension, 255, ''),
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'checksum_sha256' => hash_file('sha256', $file->getRealPath()),
            'visibility' => Document::VISIBILITY_OWNER_AND_ADMIN,
        ]);
        $document->save();

        return $document;
    }
}
