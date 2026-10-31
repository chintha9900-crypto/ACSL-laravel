<?php

namespace App\Http\Requests\Member;

use App\Models\Membership;
use App\Models\MembershipApplication;
use App\Models\SchoolClubStudentBatch;
use App\Models\SchoolClubStudentEntry;
use App\Models\User;
use App\Support\SchoolClub\StudentListPdf;
use App\Support\SchoolClub\StudentListReader;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\Validator;
use RuntimeException;

/**
 * A School Club representative's completed student template (Excel or CSV):
 * First name | Surname | Email | DOB. Only the representative of a main
 * School Club membership gets past `authorize()`, so nobody else can use the
 * list checks to probe emails. The whole list is refused if any row is
 * invalid — nothing is stored for a partly valid file.
 */
class UploadStudentListRequest extends FormRequest
{
    /**
     * Earliest date of birth accepted.
     */
    private const EARLIEST_DATE_OF_BIRTH = '1900-01-01';

    /**
     * A student name in English (Latin) letters: it starts with a letter and
     * may contain accented Latin letters, spaces, hyphens, apostrophes and
     * full stops. Sinhala, Tamil (alone or mixed with English), digits and
     * other symbols are refused.
     */
    private const NAME_PATTERN = "/^\p{Latin}[\p{Latin}\p{Mn} '’.\-]*$/u";

    /**
     * @var list<array{row: int, first_name: string, surname: string, full_name: string, email: string, date_of_birth: string}>
     */
    private array $students = [];

    public function authorize(): bool
    {
        return $this->schoolClub() !== null;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'students_file' => ['required', File::types(['csv', 'txt', 'xlsx'])->max(2048)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'students_file.required' => 'Please attach the completed student template (Excel).',
            'students_file.mimes' => 'The student list must be the Excel (.xlsx) template, or a CSV file with the same columns.',
            'students_file.max' => 'The student list must not be larger than 2 MB.',
        ];
    }

    /**
     * Reads the list and validates every row: first name, surname, a valid
     * and unique email that has no account and is not already on another open
     * or approved student list or open application, and a real date of birth
     * in DD/MM/YYYY that is in the past. Errors name the spreadsheet row.
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
                    $rows = app(StudentListReader::class)->read($this->file('students_file'));
                } catch (RuntimeException $exception) {
                    $validator->errors()->add('students_file', $exception->getMessage());

                    return;
                }

                $errors = [];
                $seen = [];
                $students = [];
                foreach ($rows as $row) {
                    $line = "Row {$row['row']}";

                    if ($row['extra_columns']) {
                        $errors[] = "{$line}: only the four template columns (First name, Surname, Email, DOB) may be filled in.";
                    }

                    foreach (['first_name' => 'first name', 'surname' => 'surname'] as $field => $label) {
                        if ($row[$field] === '') {
                            $errors[] = "{$line}: enter the student's {$label}.";
                        } elseif (mb_strlen($row[$field]) > 80) {
                            $errors[] = "{$line}: the {$label} must not be longer than 80 characters.";
                        } elseif (preg_match(self::NAME_PATTERN, $row[$field]) !== 1 || ! StudentListPdf::canPrint($row[$field])) {
                            $errors[] = "{$line}: the {$label} must be written in English letters only (spaces, hyphens, apostrophes and accented letters are allowed). Sinhala and Tamil characters are not accepted.";
                        }
                    }

                    $email = Str::lower($row['email']);
                    if ($email === '') {
                        $errors[] = "{$line}: enter the student's email address.";
                    } elseif (ValidatorFacade::make(['email' => $email], ['email' => ['email:rfc,filter_unicode', 'max:255']])->fails() || ! StudentListPdf::canPrint($email)) {
                        $errors[] = "{$line}: \"{$row['email']}\" is not a valid email address.";
                    } elseif (isset($seen[$email])) {
                        $errors[] = "{$line}: {$email} appears more than once (first on row {$seen[$email]}). Each student needs a unique email address.";
                    } else {
                        $seen[$email] = $row['row'];
                    }

                    $dateOfBirth = $this->dateOfBirth($row['date_of_birth']);
                    if ($row['date_of_birth'] === '') {
                        $errors[] = "{$line}: enter the student's date of birth (DD/MM/YYYY).";
                    } elseif ($dateOfBirth === null) {
                        $errors[] = "{$line}: \"{$row['date_of_birth']}\" is not a valid date of birth. Use the format DD/MM/YYYY, for example 15/04/2010.";
                    } elseif (! $this->isEligibleDateOfBirth($dateOfBirth)) {
                        $errors[] = "{$line}: the date of birth {$row['date_of_birth']} is not accepted. It must be a past date, on or after 01/01/1900.";
                    }

                    $students[] = [
                        'row' => $row['row'],
                        'first_name' => $row['first_name'],
                        'surname' => $row['surname'],
                        'full_name' => $row['first_name'].' '.$row['surname'],
                        'email' => $email,
                        'date_of_birth' => $dateOfBirth?->toDateString() ?? '',
                    ];
                }

                $emails = array_keys($seen);
                $replacing = $this->replacingBatch();
                $taken = array_unique([
                    ...User::query()->whereIn('email', $emails)->pluck('email')->map(fn (string $e): string => Str::lower($e))->all(),
                    ...SchoolClubStudentEntry::query()
                        ->whereIn('email', $emails)
                        ->when($replacing, fn ($query, SchoolClubStudentBatch $batch) => $query->where('school_club_student_batch_id', '!=', $batch->id))
                        ->whereHas('batch', fn ($query) => $query->whereIn('status', [
                            SchoolClubStudentBatch::STATUS_AWAITING_SIGNED_LIST,
                            ...SchoolClubStudentBatch::OPEN_STATUSES,
                            SchoolClubStudentBatch::STATUS_APPROVED,
                        ]))
                        ->pluck('email')->all(),
                    ...MembershipApplication::query()->whereIn('email', $emails)->whereIn('status', MembershipApplication::OPEN_STATUSES)->pluck('email')->all(),
                ]);

                foreach ($students as $student) {
                    if (in_array($student['email'], $taken, true)) {
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
     * @return list<array{row: int, first_name: string, surname: string, full_name: string, email: string, date_of_birth: string}>
     */
    public function students(): array
    {
        return $this->students;
    }

    public function spreadsheet(): UploadedFile
    {
        return $this->file('students_file');
    }

    /**
     * The batch this upload replaces: the one in the URL (a resubmission), or
     * else the club's own unsent list still waiting for its signed PDF.
     */
    public function replacingBatch(): ?SchoolClubStudentBatch
    {
        $batch = $this->route('batch');
        if ($batch instanceof SchoolClubStudentBatch) {
            return $batch;
        }

        $club = $this->schoolClub();

        return $club === null ? null : SchoolClubStudentBatch::query()
            ->where('membership_id', $club->id)
            ->where('status', SchoolClubStudentBatch::STATUS_AWAITING_SIGNED_LIST)
            ->whereNull('request_message')
            ->first();
    }

    private function schoolClub(): ?Membership
    {
        $user = $this->user();
        $membership = $user === null ? null : Membership::query()->where('user_id', $user->id)->with('category')->first();

        return $membership !== null && $membership->isSchoolClubMain() ? $membership : null;
    }

    /**
     * A strict DD/MM/YYYY date that exists on the calendar, or null.
     */
    private function dateOfBirth(string $value): ?CarbonImmutable
    {
        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $value, $match) !== 1 || ! checkdate((int) $match[2], (int) $match[1], (int) $match[3])) {
            return null;
        }

        return CarbonImmutable::createFromDate((int) $match[3], (int) $match[2], (int) $match[1])->startOfDay();
    }

    /**
     * No age rule for School Club students is defined by the club, so only an
     * impossible date is refused: in the future, today, or before 1900.
     */
    private function isEligibleDateOfBirth(CarbonImmutable $dateOfBirth): bool
    {
        $today = CarbonImmutable::now(config('membership.business_timezone'))->toDateString();

        return $dateOfBirth->toDateString() < $today
            && $dateOfBirth->toDateString() >= self::EARLIEST_DATE_OF_BIRTH;
    }
}
