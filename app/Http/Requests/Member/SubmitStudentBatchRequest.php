<?php

namespace App\Http\Requests\Member;

use App\Models\Membership;
use App\Models\MembershipApplication;
use App\Models\SchoolClubStudentBatch;
use App\Models\SchoolClubStudentEntry;
use App\Models\User;
use App\Support\SchoolClub\StudentListReader;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\Validator;
use RuntimeException;

/**
 * A School Club representative's batch of students: a CSV/Excel list
 * (full name + unique email) and the principal-signed student list (PDF).
 * Only the representative of a main School Club membership gets past
 * `authorize()`, so nobody else can use the list checks to probe emails.
 */
class SubmitStudentBatchRequest extends FormRequest
{
    /**
     * @var list<array{row: int, full_name: string, email: string}>
     */
    private array $students = [];

    public function authorize(): bool
    {
        $user = $this->user();
        $membership = $user === null ? null : Membership::query()->where('user_id', $user->id)->with('category')->first();

        return $membership !== null && $membership->isSchoolClubMain();
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'students_file' => ['required', File::types(['csv', 'txt', 'xlsx'])->max(2048)],
            'principal_list' => ['required', File::types(['pdf'])->max((int) config('uploads.aviation_proof.max_kb'))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'students_file.required' => 'Please attach the student list (CSV or Excel).',
            'students_file.mimes' => 'The student list must be a CSV or Excel (.xlsx) file.',
            'students_file.max' => 'The student list must not be larger than 2 MB.',
            'principal_list.required' => 'Please attach the principal-signed student list (PDF).',
            'principal_list.mimes' => 'The principal-signed student list must be a PDF file.',
            'principal_list.max' => 'The principal-signed student list must not be larger than '.round(((int) config('uploads.aviation_proof.max_kb')) / 1024, 1).' MB.',
        ];
    }

    /**
     * Reads the list and validates every row: a name, a valid email, no
     * duplicates within the list, and no email that already has an account or
     * is already on an open or approved student batch or an open application.
     * Errors name the spreadsheet row.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                try {
                    $students = app(StudentListReader::class)->read($this->file('students_file'));
                } catch (RuntimeException $exception) {
                    $validator->errors()->add('students_file', $exception->getMessage());

                    return;
                }

                $errors = [];
                $seen = [];
                foreach ($students as $index => $student) {
                    $email = Str::lower($student['email']);
                    $students[$index]['email'] = $email;

                    if ($student['full_name'] === '' || mb_strlen($student['full_name']) > 160) {
                        $errors[] = "Row {$student['row']}: enter the student's full name (up to 160 characters).";
                    }

                    if (ValidatorFacade::make(['email' => $email], ['email' => ['required', 'email:rfc,filter_unicode', 'max:255']])->fails()) {
                        $errors[] = "Row {$student['row']}: \"{$student['email']}\" is not a valid email address.";

                        continue;
                    }

                    if (isset($seen[$email])) {
                        $errors[] = "Row {$student['row']}: {$email} appears more than once (first on row {$seen[$email]}). Each student needs a unique email address.";
                    }
                    $seen[$email] = $seen[$email] ?? $student['row'];
                }

                $emails = array_keys($seen);
                $taken = array_unique([
                    ...User::query()->whereIn('email', $emails)->pluck('email')->map(fn (string $e): string => Str::lower($e))->all(),
                    ...SchoolClubStudentEntry::query()
                        ->whereIn('email', $emails)
                        ->when($this->route('batch'), fn ($query, SchoolClubStudentBatch $batch) => $query->where('school_club_student_batch_id', '!=', $batch->id))
                        ->whereHas('batch', fn ($query) => $query->whereIn('status', [...SchoolClubStudentBatch::OPEN_STATUSES, SchoolClubStudentBatch::STATUS_APPROVED]))
                        ->pluck('email')->all(),
                    ...MembershipApplication::query()->whereIn('email', $emails)->whereIn('status', MembershipApplication::OPEN_STATUSES)->pluck('email')->all(),
                ]);

                foreach ($students as $student) {
                    if (in_array(Str::lower($student['email']), $taken, true)) {
                        $errors[] = "Row {$student['row']}: {$student['email']} is already registered or already submitted. Each student needs their own unused email address.";
                    }
                }

                foreach (array_slice($errors, 0, 20) as $error) {
                    $validator->errors()->add('students_file', $error);
                }
                if (count($errors) > 20) {
                    $validator->errors()->add('students_file', 'And '.(count($errors) - 20).' more problems. Please correct the list and try again.');
                }

                $this->students = $students;
            },
        ];
    }

    /**
     * @return list<array{row: int, full_name: string, email: string}>
     */
    public function students(): array
    {
        return $this->students;
    }

    public function principalList(): UploadedFile
    {
        return $this->file('principal_list');
    }
}
