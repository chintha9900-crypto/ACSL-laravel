<?php

namespace App\Actions\Membership;

use App\Models\Document;
use App\Models\MembershipApplication;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class StoreProofDocument
{
    /**
     * Extensions a stored proof file may have, chosen from its detected content.
     *
     * @var list<string>
     */
    private const STORED_EXTENSIONS = ['pdf', 'jpg', 'png'];

    /**
     * Write one proof file to the private disk under a server-generated path and
     * record its metadata. The client's filename is kept for display only. Callers
     * run this inside a transaction and delete `$storedPaths` if it fails.
     *
     * @param  list<string>  $storedPaths  Receives the path written (for cleanup).
     * @param  int|null  $detailsRequestId  Set when the file answers a "more details" request.
     */
    public function handle(MembershipApplication $application, UploadedFile $file, array &$storedPaths, ?int $detailsRequestId = null): Document
    {
        $extension = $file->guessExtension();
        $extension = $extension === 'jpeg' ? 'jpg' : $extension;

        if (! in_array($extension, self::STORED_EXTENSIONS, true)) {
            throw new RuntimeException('Unsupported proof document type.');
        }

        $path = 'aviation-proof/'.$application->public_id.'/'.Str::lower((string) Str::ulid()).'.'.$extension;

        $stream = fopen($file->getRealPath(), 'rb');

        try {
            $written = Storage::disk(Document::DISK_PRIVATE)->put($path, $stream, 'private');
        } finally {
            fclose($stream);
        }

        if ($written === false) {
            throw new RuntimeException('The proof document could not be stored.');
        }

        $storedPaths[] = $path;

        $document = new Document([
            'kind' => Document::KIND_AVIATION_PROOF,
            'membership_application_id' => $application->id,
            'membership_details_request_id' => $detailsRequestId,
            'uploaded_by_user_id' => null,
            'disk' => Document::DISK_PRIVATE,
            'storage_path' => $path,
            'original_filename' => $this->displayName($file),
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'checksum_sha256' => hash_file('sha256', $file->getRealPath()),
        ]);
        $document->save();

        return $document;
    }

    /**
     * A safe display name: base name only, control characters removed, length limited.
     */
    private function displayName(UploadedFile $file): string
    {
        $name = basename(str_replace('\\', '/', $file->getClientOriginalName()));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';

        return Str::limit(trim($name) !== '' ? $name : 'document', 255, '');
    }
}
