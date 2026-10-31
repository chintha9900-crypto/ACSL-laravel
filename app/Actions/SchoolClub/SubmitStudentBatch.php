<?php

namespace App\Actions\SchoolClub;

use App\Models\Document;
use App\Models\Membership;
use App\Models\SchoolClubStudentBatch;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class SubmitStudentBatch
{
    public function __construct(private readonly StoreStudentBatchDocument $storeDocument) {}

    /**
     * Final step of adding students: attach the scanned, principal-signed
     * student list (PDF) to a list waiting for it and send the batch to the
     * existing admin review (`submitted`). Creates no account or membership:
     * those exist only once an admin approves the batch.
     *
     * One transaction; if it fails, the stored PDF is removed again.
     */
    public function handle(Membership $membership, User $representative, SchoolClubStudentBatch $batch, UploadedFile $principalList): SchoolClubStudentBatch
    {
        $storedPath = null;

        try {
            return DB::transaction(function () use ($membership, $representative, $batch, $principalList, &$storedPath): SchoolClubStudentBatch {
                $now = now();

                $changed = DB::table('school_club_student_batches')
                    ->where('id', $batch->id)
                    ->where('membership_id', $membership->id)
                    ->where('status', SchoolClubStudentBatch::STATUS_AWAITING_SIGNED_LIST)
                    ->update([
                        'status' => SchoolClubStudentBatch::STATUS_SUBMITTED,
                        'submitted_by_user_id' => $representative->id,
                        'submitted_at' => $now,
                        'updated_at' => $now,
                    ]);

                if ($changed !== 1) {
                    throw new RuntimeException('This student list is not waiting for a signed list.');
                }

                $batch->refresh();

                $this->storeDocument->handle($batch, $representative, $principalList, Document::KIND_SCHOOL_CLUB_STUDENT_LIST, 'pdf', $storedPath);

                DB::table('audit_logs')->insert([
                    'user_id' => $representative->id,
                    'actor_type' => 'user',
                    'event' => 'school_club.student_batch_submitted',
                    'subject_type' => 'school_club_student_batch',
                    'subject_id' => $batch->id,
                    'old_values' => json_encode(['status' => SchoolClubStudentBatch::STATUS_AWAITING_SIGNED_LIST]),
                    'new_values' => json_encode(['status' => SchoolClubStudentBatch::STATUS_SUBMITTED, 'students_count' => $batch->students_count]),
                    'ip_address' => Request::ip(),
                    'user_agent' => Str::limit((string) Request::userAgent(), 512, ''),
                    'created_at' => $now,
                ]);

                return $batch;
            });
        } catch (Throwable $exception) {
            if ($storedPath !== null) {
                Storage::disk(Document::DISK_PRIVATE)->delete($storedPath);
            }

            throw $exception;
        }
    }
}
