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

class SubmitStudentBatch
{
    /**
     * Submit a batch of students for a main School Club membership — or, with
     * `$batch`, resubmit one an admin returned for more details (its entries and
     * principal-signed list are replaced). Creates no account or membership:
     * those exist only once an admin approves the batch.
     *
     * One transaction; if it fails, the stored list is removed again.
     *
     * @param  list<array{row: int, full_name: string, email: string}>  $students
     */
    public function handle(Membership $membership, User $representative, array $students, UploadedFile $principalList, ?SchoolClubStudentBatch $batch = null): SchoolClubStudentBatch
    {
        $storedPath = null;

        try {
            return DB::transaction(function () use ($membership, $representative, $students, $principalList, $batch, &$storedPath): SchoolClubStudentBatch {
                $now = now();

                if ($batch !== null) {
                    $changed = DB::table('school_club_student_batches')
                        ->where('id', $batch->id)
                        ->where('membership_id', $membership->id)
                        ->where('status', SchoolClubStudentBatch::STATUS_MORE_DETAILS_REQUIRED)
                        ->update([
                            'status' => SchoolClubStudentBatch::STATUS_SUBMITTED,
                            'students_count' => count($students),
                            'submitted_at' => $now,
                            'updated_at' => $now,
                        ]);

                    if ($changed !== 1) {
                        throw new RuntimeException('This student batch can no longer be updated.');
                    }

                    SchoolClubStudentEntry::query()->where('school_club_student_batch_id', $batch->id)->delete();
                    $batch->refresh();
                } else {
                    $batch = SchoolClubStudentBatch::forceCreate([
                        'membership_id' => $membership->id,
                        'submitted_by_user_id' => $representative->id,
                        'status' => SchoolClubStudentBatch::STATUS_SUBMITTED,
                        'students_count' => count($students),
                        'submitted_at' => $now,
                    ]);
                }

                foreach ($students as $student) {
                    SchoolClubStudentEntry::forceCreate([
                        'school_club_student_batch_id' => $batch->id,
                        'full_name' => $student['full_name'],
                        'email' => Str::lower($student['email']),
                    ]);
                }

                $storedPath = 'school-club-students/'.$batch->public_id.'/'.Str::lower((string) Str::ulid()).'.pdf';
                $stream = fopen($principalList->getRealPath(), 'rb');
                try {
                    $written = Storage::disk(Document::DISK_PRIVATE)->put($storedPath, $stream, 'private');
                } finally {
                    fclose($stream);
                }
                if ($written === false) {
                    throw new RuntimeException('The principal-signed student list could not be stored.');
                }

                (new Document([
                    'kind' => Document::KIND_SCHOOL_CLUB_STUDENT_LIST,
                    'school_club_student_batch_id' => $batch->id,
                    'uploaded_by_user_id' => $representative->id,
                    'disk' => Document::DISK_PRIVATE,
                    'storage_path' => $storedPath,
                    'original_filename' => Str::limit(basename(str_replace('\\', '/', $principalList->getClientOriginalName())) ?: 'student-list.pdf', 255, ''),
                    'mime_type' => $principalList->getMimeType(),
                    'size_bytes' => $principalList->getSize(),
                    'checksum_sha256' => hash_file('sha256', $principalList->getRealPath()),
                    'visibility' => Document::VISIBILITY_OWNER_AND_ADMIN,
                ]))->save();

                DB::table('audit_logs')->insert([
                    'user_id' => $representative->id,
                    'actor_type' => 'user',
                    'event' => 'school_club.student_batch_submitted',
                    'subject_type' => 'school_club_student_batch',
                    'subject_id' => $batch->id,
                    'new_values' => json_encode(['status' => SchoolClubStudentBatch::STATUS_SUBMITTED, 'students_count' => count($students)]),
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
