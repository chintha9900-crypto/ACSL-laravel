<?php

namespace App\Actions\Membership;

use App\Models\Document;
use App\Models\MembershipApplication;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class SubmitMembershipApplication
{
    /**
     * Extensions a stored proof file may have, chosen from its detected content.
     *
     * @var list<string>
     */
    private const STORED_EXTENSIONS = ['pdf', 'jpg', 'png'];

    /**
     * Create a `submitted` application and its aviation proof documents as one
     * unit (docs/database/15: application + documents + status history + audit).
     *
     * If anything fails, the database transaction is rolled back and every file
     * already written to the private disk is removed. No user, membership, term,
     * payment or notification is created.
     *
     * @param  array<string, mixed>  $data  Validated application fields (fillable only).
     * @param  list<UploadedFile>  $proofFiles  Already validated aviation proof uploads.
     */
    public function handle(array $data, array $proofFiles): MembershipApplication
    {
        $storedPaths = [];

        try {
            return DB::transaction(function () use ($data, $proofFiles, &$storedPaths): MembershipApplication {
                $application = new MembershipApplication($data);
                $application->submitted_at = now();
                $application->save();

                foreach ($proofFiles as $file) {
                    $this->storeProofDocument($application, $file, $storedPaths);
                }

                $this->recordSubmission($application);

                return $application;
            });
        } catch (Throwable $exception) {
            foreach ($storedPaths as $path) {
                Storage::disk(Document::DISK_PRIVATE)->delete($path);
            }

            if ($exception instanceof UniqueConstraintViolationException
                && str_contains($exception->getMessage(), 'open_email_key')) {
                throw ValidationException::withMessages([
                    'email' => 'An application or membership already exists for this email address. If you need help, please contact ACI.',
                ]);
            }

            throw $exception;
        }
    }

    /**
     * Write one proof file to the private disk under a server-generated path and
     * record its metadata. The client's filename is kept for display only.
     *
     * @param  list<string>  $storedPaths
     */
    private function storeProofDocument(MembershipApplication $application, UploadedFile $file, array &$storedPaths): void
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
            'uploaded_by_user_id' => null,
            'disk' => Document::DISK_PRIVATE,
            'storage_path' => $path,
            'original_filename' => $this->displayName($file),
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'checksum_sha256' => hash_file('sha256', $file->getRealPath()),
        ]);
        $document->save();
    }

    /**
     * The status-history and audit rows for the submission (docs/database/15).
     */
    private function recordSubmission(MembershipApplication $application): void
    {
        DB::table('membership_status_history')->insert([
            'membership_application_id' => $application->id,
            'event' => 'application.submitted',
            'to_status' => MembershipApplication::STATUS_SUBMITTED,
            'actor_type' => 'applicant',
            'created_at' => now(),
        ]);

        DB::table('audit_logs')->insert([
            'user_id' => null,
            'actor_type' => 'guest',
            'event' => 'membership_application.submitted',
            'subject_type' => 'membership_application',
            'subject_id' => $application->id,
            'new_values' => json_encode(['status' => MembershipApplication::STATUS_SUBMITTED]),
            'ip_address' => Request::ip(),
            'user_agent' => Str::limit((string) Request::userAgent(), 512, ''),
            'created_at' => now(),
        ]);
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
