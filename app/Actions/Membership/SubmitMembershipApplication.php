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
use Throwable;

class SubmitMembershipApplication
{
    public function __construct(private StoreProofDocument $storeProofDocument) {}

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
                    $this->storeProofDocument->handle($application, $file, $storedPaths);
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
}
