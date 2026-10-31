<?php

namespace App\Actions\SchoolClub;

use App\Models\Document;
use App\Models\Membership;
use App\Models\SchoolClubStudentBatch;
use App\Models\SchoolClubStudentEntry;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class StageStudentList
{
    public function __construct(private readonly StoreStudentBatchDocument $storeDocument) {}

    /**
     * Step one of adding students: store the validated Excel list as a batch
     * waiting for its principal-signed PDF (`awaiting_signed_list`). Admins do
     * not see it yet, and no account or membership is created.
     *
     * `$batch` is the batch being replaced: one returned for more details, or
     * the club's own list still waiting for its signed PDF. Its entries are
     * replaced; previous uploads stay on record. Otherwise a new batch starts.
     *
     * One transaction; if it fails, the stored file is removed again.
     *
     * @param  list<array{row: int, first_name: string, surname: string, full_name: string, email: string, date_of_birth: string}>  $students
     */
    public function handle(Membership $membership, User $representative, array $students, UploadedFile $spreadsheet, ?SchoolClubStudentBatch $batch = null): SchoolClubStudentBatch
    {
        $storedPath = null;

        try {
            return DB::transaction(function () use ($membership, $representative, $students, $spreadsheet, $batch, &$storedPath): SchoolClubStudentBatch {
                $now = now();

                // Serialise uploads for this club so two never create two unsent lists.
                Membership::query()->whereKey($membership->id)->lockForUpdate()->first();

                if ($batch !== null) {
                    // Checked under a row lock rather than by the update's affected-row
                    // count, which is 0 when a replacement changes no column value.
                    $replaceable = DB::table('school_club_student_batches')
                        ->where('id', $batch->id)
                        ->where('membership_id', $membership->id)
                        ->whereIn('status', [SchoolClubStudentBatch::STATUS_MORE_DETAILS_REQUIRED, SchoolClubStudentBatch::STATUS_AWAITING_SIGNED_LIST])
                        ->lockForUpdate()
                        ->exists();

                    if (! $replaceable) {
                        throw new RuntimeException('This student list can no longer be changed.');
                    }

                    DB::table('school_club_student_batches')->where('id', $batch->id)->update([
                        'status' => SchoolClubStudentBatch::STATUS_AWAITING_SIGNED_LIST,
                        'students_count' => count($students),
                        'updated_at' => $now,
                    ]);

                    SchoolClubStudentEntry::query()->where('school_club_student_batch_id', $batch->id)->delete();
                    $batch->refresh();
                } else {
                    $batch = SchoolClubStudentBatch::forceCreate([
                        'membership_id' => $membership->id,
                        'submitted_by_user_id' => $representative->id,
                        'status' => SchoolClubStudentBatch::STATUS_AWAITING_SIGNED_LIST,
                        'students_count' => count($students),
                        'submitted_at' => $now,
                    ]);
                }

                foreach ($students as $student) {
                    SchoolClubStudentEntry::forceCreate([
                        'school_club_student_batch_id' => $batch->id,
                        'full_name' => $student['full_name'],
                        'first_name' => $student['first_name'],
                        'surname' => $student['surname'],
                        'date_of_birth' => $student['date_of_birth'],
                        'email' => Str::lower($student['email']),
                    ]);
                }

                $extension = strtolower($spreadsheet->getClientOriginalExtension()) === 'xlsx' ? 'xlsx' : 'csv';
                $this->storeDocument->handle($batch, $representative, $spreadsheet, Document::KIND_SCHOOL_CLUB_STUDENT_SPREADSHEET, $extension, $storedPath);

                DB::table('audit_logs')->insert([
                    'user_id' => $representative->id,
                    'actor_type' => 'user',
                    'event' => 'school_club.student_list_uploaded',
                    'subject_type' => 'school_club_student_batch',
                    'subject_id' => $batch->id,
                    'new_values' => json_encode(['status' => SchoolClubStudentBatch::STATUS_AWAITING_SIGNED_LIST, 'students_count' => count($students)]),
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
