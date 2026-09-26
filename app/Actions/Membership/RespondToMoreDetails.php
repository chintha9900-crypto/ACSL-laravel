<?php

namespace App\Actions\Membership;

use App\Exceptions\MoreDetailsNotRequestedException;
use App\Models\Document;
use App\Models\MembershipApplication;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class RespondToMoreDetails
{
    public function __construct(private StoreProofDocument $storeProofDocument) {}

    /**
     * Record the applicant's answer to the open "more details" request and return
     * the application to `submitted` (docs/database/04 §2: submitted ⇄ more_details_required).
     *
     * The request is closed with a compare-and-set on `responded_at IS NULL` and the
     * application with `status = 'more_details_required'`, so a repeated or concurrent
     * submission changes nothing and gets an exception. Request, status, documents,
     * history and audit are written in one transaction; stored files are deleted if it
     * fails. No membership, payment, user or notification is created.
     *
     * @param  list<UploadedFile>  $files  Already validated supporting documents.
     *
     * @throws MoreDetailsNotRequestedException
     */
    public function handle(MembershipApplication $application, ?string $message, array $files): void
    {
        $storedPaths = [];

        try {
            DB::transaction(function () use ($application, $message, $files, &$storedPaths): void {
                $now = now();

                $request = DB::table('membership_details_requests')
                    ->where('membership_application_id', $application->id)
                    ->whereNull('responded_at')
                    ->lockForUpdate()
                    ->first();

                if ($request === null) {
                    throw new MoreDetailsNotRequestedException('There is no open request for more details on this application.');
                }

                $closed = DB::table('membership_details_requests')
                    ->where('id', $request->id)
                    ->whereNull('responded_at')
                    ->update(['responded_at' => $now, 'response_message' => filled($message) ? trim($message) : null, 'updated_at' => $now]);

                $reopened = DB::table('membership_applications')
                    ->where('id', $application->id)
                    ->where('status', MembershipApplication::STATUS_MORE_DETAILS_REQUIRED)
                    ->update(['status' => MembershipApplication::STATUS_SUBMITTED, 'updated_at' => $now]);

                if ($closed !== 1 || $reopened !== 1) {
                    throw new MoreDetailsNotRequestedException('There is no open request for more details on this application.');
                }

                foreach ($files as $file) {
                    $this->storeProofDocument->handle($application, $file, $storedPaths, (int) $request->id);
                }

                DB::table('membership_status_history')->insert([
                    'membership_application_id' => $application->id,
                    'event' => 'application.details_responded',
                    'from_status' => MembershipApplication::STATUS_MORE_DETAILS_REQUIRED,
                    'to_status' => MembershipApplication::STATUS_SUBMITTED,
                    'actor_type' => 'applicant',
                    'created_at' => $now,
                ]);

                DB::table('audit_logs')->insert([
                    'user_id' => null,
                    'actor_type' => 'guest',
                    'event' => 'membership_application.details_responded',
                    'subject_type' => 'membership_application',
                    'subject_id' => $application->id,
                    'old_values' => json_encode(['status' => MembershipApplication::STATUS_MORE_DETAILS_REQUIRED]),
                    'new_values' => json_encode(['status' => MembershipApplication::STATUS_SUBMITTED]),
                    'ip_address' => Request::ip(),
                    'user_agent' => Str::limit((string) Request::userAgent(), 512, ''),
                    'created_at' => $now,
                ]);
            });
        } catch (Throwable $exception) {
            foreach ($storedPaths as $path) {
                Storage::disk(Document::DISK_PRIVATE)->delete($path);
            }

            throw $exception;
        }
    }
}
