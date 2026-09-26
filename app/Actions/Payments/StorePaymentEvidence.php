<?php

namespace App\Actions\Payments;

use App\Models\Document;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Store one payment-evidence file, exactly like `StoreProofDocument` (same
 * private disk, server-generated path, content-sniffed extension) but owned by
 * the submitting member so they can view it back (docs/database/07 §3:
 * `visibility = owner_and_admin`).
 */
class StorePaymentEvidence
{
    /**
     * @var list<string>
     */
    private const STORED_EXTENSIONS = ['pdf', 'jpg', 'png'];

    /**
     * @param  list<string>  $storedPaths  Receives the path written (for cleanup on failure).
     */
    public function handle(Payment $payment, User $uploadedBy, UploadedFile $file, array &$storedPaths): Document
    {
        $extension = $file->guessExtension();
        $extension = $extension === 'jpeg' ? 'jpg' : $extension;

        if (! in_array($extension, self::STORED_EXTENSIONS, true)) {
            throw new RuntimeException('Unsupported payment evidence document type.');
        }

        $path = 'payment-evidence/'.$payment->public_id.'/'.Str::lower((string) Str::ulid()).'.'.$extension;

        $stream = fopen($file->getRealPath(), 'rb');

        try {
            $written = Storage::disk(Document::DISK_PRIVATE)->put($path, $stream, 'private');
        } finally {
            fclose($stream);
        }

        if ($written === false) {
            throw new RuntimeException('The payment evidence could not be stored.');
        }

        $storedPaths[] = $path;

        $document = new Document([
            'kind' => Document::KIND_PAYMENT_EVIDENCE,
            'payment_id' => $payment->id,
            'uploaded_by_user_id' => $uploadedBy->id,
            'disk' => Document::DISK_PRIVATE,
            'storage_path' => $path,
            'original_filename' => $this->displayName($file),
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'checksum_sha256' => hash_file('sha256', $file->getRealPath()),
            'visibility' => Document::VISIBILITY_OWNER_AND_ADMIN,
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
