<?php

namespace App\Http\Requests\Membership;

use App\Models\MembershipApplication;
use App\Models\MembershipCategory;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\Validator;

class StoreMembershipApplicationRequest extends FormRequest
{
    /**
     * Shown whenever the email cannot open a new application. Deliberately the
     * same for an open application, an existing membership and an existing
     * account, so the form never reveals which of those exists.
     */
    public const EMAIL_UNAVAILABLE_MESSAGE = 'We cannot accept a new application with this email address. If you have already applied, or you already have an ACI account or membership, please sign in or contact ACI for help.';

    /**
     * E.164: "+", a country code that does not start with 0, at most 15 digits in all.
     */
    private const E164_PATTERN = '/^\+[1-9][0-9]{6,14}$/';

    /**
     * The application is public; access is not decided by a session.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalise input before validation. Email is stored lower-cased.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower(trim($this->input('email')))]);
        }

        // A company website typed without a scheme ("www.example.com") is
        // given https:// so it can pass the `url` rule; anything still invalid
        // is reported beside the field as before.
        $website = $this->input('company_website');
        if (is_string($website) && trim($website) !== '' && preg_match('#^[a-z][a-z0-9+.-]*://#i', trim($website)) !== 1) {
            $this->merge(['company_website' => 'https://'.trim($website)]);
        }
    }

    /**
     * Only fields of the approved schema are accepted. Category-specific fields
     * are required for their category and dropped for any other.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $student = 'exclude_unless:category,'.MembershipCategory::CODE_STUDENT;
        $veteran = 'exclude_unless:category,'.MembershipCategory::CODE_VETERAN;
        // Corporate and School Club share the organisation (company_*) fields:
        // for a School Club they hold the school's name and contact details.
        $corporate = 'exclude_unless:category,'.MembershipCategory::CODE_CORPORATE.','.MembershipCategory::CODE_SCHOOL_CLUB;
        $schoolClub = $this->input('category') === MembershipCategory::CODE_SCHOOL_CLUB;
        // Aviation Enthusiast is the one category with no eligibility to
        // prove — every other case (including a missing/invalid category,
        // which must still be reported as its own error) keeps requiring
        // the proof/company-letter upload, so this excludes on Enthusiast
        // specifically rather than requiring on an enumerated allow-list.
        $needsProof = 'exclude_if:category,'.MembershipCategory::CODE_ENTHUSIAST;
        $proof = config('uploads.aviation_proof');

        return [
            'category' => [
                'required',
                'string',
                Rule::exists('membership_categories', 'code')->where('is_active', true),
            ],
            'full_name' => ['required', 'string', 'max:160'],
            // `filter_unicode` allows Unicode local parts (josé@…) and punycode
            // (xn--…) domains, while still rejecting addresses such as a@b.
            'email' => ['required', 'string', 'email:rfc,filter_unicode', 'max:255'],
            'mobile' => ['required', 'string', 'max:40', $this->internationalPhone('mobile number')],
            'address' => ['required', 'string', 'max:400'],
            'aviation_role' => ['required', 'string', 'max:160'],
            'aviation_organisation' => ['required', 'string', 'max:200', ...($schoolClub ? [Rule::in(MembershipApplication::SCHOOL_CLUB_POSITIONS)] : [])],
            'study_start_date' => [$student, 'required', 'date'],
            'expected_completion_date' => [$student, 'required', 'date', 'after_or_equal:study_start_date'],
            'years_experience' => [$veteran, 'required', 'integer', 'between:0,80'],
            'previous_employers' => [$veteran, 'required', 'string', 'max:2000'],
            'company_name' => [$corporate, 'required', 'string', 'max:160'],
            'company_email' => [$corporate, 'required', 'string', 'email:rfc,filter_unicode', 'max:255'],
            'company_phone' => [$corporate, 'required', 'string', 'max:40', $this->internationalPhone('company phone number')],
            'company_website' => [$corporate, 'nullable', 'string', 'url', 'max:255'],
            'proof_documents' => [$needsProof, 'required', 'array', 'min:1', 'max:'.$proof['max_files']],
            'proof_documents.*' => [
                $needsProof,
                'required',
                // A School Club's principal request letter must be a PDF.
                File::types($schoolClub ? ['pdf'] : $proof['extensions'])->max($proof['max_kb']),
            ],
            // The declaration is a submission-time confirmation only — it is
            // never stored (see applicationData() below), just like the
            // reference application never persisted a "read the rules" tick.
            'declaration' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $proof = config('uploads.aviation_proof');

        return [
            'category.exists' => 'Please choose one of the available membership categories.',
            'email.email' => 'Enter a valid email address, for example name@example.com.',
            'company_email.email' => 'Enter a valid company email address, for example info@example.com.',
            'proof_documents.required' => $this->input('category') === MembershipCategory::CODE_SCHOOL_CLUB
                ? "The principal's official request letter is required. Please upload it as a PDF."
                : 'Aviation eligibility proof is required. Please upload at least one document.',
            'proof_documents.min' => 'Aviation eligibility proof is required. Please upload at least one document.',
            'proof_documents.max' => 'You can upload at most '.$proof['max_files'].' documents.',
            'proof_documents.*.mimes' => $this->input('category') === MembershipCategory::CODE_SCHOOL_CLUB
                ? "The principal's official request letter must be a PDF file."
                : 'Each document must be a PDF, JPG or PNG file.',
            'proof_documents.*.max' => 'Each document must not be larger than '.round($proof['max_kb'] / 1024, 1).' MB.',
            'proof_documents.*.uploaded' => 'A document could not be uploaded. Please check its size and try again.',
            'declaration.accepted' => 'Please confirm the declaration to submit your application.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'full_name' => 'full name',
            'aviation_role' => $this->roleLabel(),
            'aviation_organisation' => $this->organisationLabel(),
            'study_start_date' => 'course start date',
            'expected_completion_date' => 'expected completion date',
            'years_experience' => 'years of experience',
            'previous_employers' => 'previous employers',
            ...($this->input('category') === MembershipCategory::CODE_SCHOOL_CLUB
                ? [
                    'company_name' => 'school name',
                    'company_email' => 'school email',
                    'company_phone' => 'school phone',
                    'company_website' => 'school website',
                    'address' => 'school address',
                    'full_name' => 'representative name',
                    'email' => 'representative email',
                    'mobile' => 'representative telephone number',
                ]
                : [
                    'company_name' => 'company name',
                    'company_email' => 'company email',
                    'company_phone' => 'company phone',
                    'company_website' => 'company website',
                ]),
            'proof_documents' => match ($this->input('category')) {
                MembershipCategory::CODE_CORPORATE => 'company membership/request letter',
                MembershipCategory::CODE_SCHOOL_CLUB => "principal's official request letter",
                default => 'aviation proof',
            },
        ];
    }

    /**
     * Duplicate protection: an open application or an existing membership for
     * this email (docs/database/15 R-06), or an existing user account — activation
     * never reuses an existing account (OD-06), so such an application could
     * never be activated. A rejected application does not block a new one. The
     * database's unique `open_email_key` remains the backstop against a
     * concurrent duplicate. One neutral message covers every case.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('email')) {
                    return;
                }

                if ($this->emailIsUnavailable($this->string('email')->toString())) {
                    $validator->errors()->add('email', self::EMAIL_UNAVAILABLE_MESSAGE);
                }
            },
        ];
    }

    /**
     * The data for the application row, with the category code resolved to its
     * database id. Nothing the applicant could use to set status, review,
     * decision or user columns is ever included.
     *
     * @return array<string, mixed>
     */
    public function applicationData(): array
    {
        $category = MembershipCategory::query()
            ->where('code', $this->string('category')->toString())
            ->where('is_active', true)
            ->firstOrFail();

        return [
            ...$this->safe()->only([
                'full_name',
                'email',
                'mobile',
                'address',
                'aviation_role',
                'aviation_organisation',
                'study_start_date',
                'expected_completion_date',
                'years_experience',
                'previous_employers',
                'company_name',
                'company_email',
                'company_phone',
                'company_website',
            ]),
            'membership_category_id' => $category->id,
            ...collect(['mobile', 'company_phone'])
                ->filter(fn (string $field): bool => $this->safe()->has($field))
                ->mapWithKeys(fn (string $field): array => [$field => self::toE164($this->safe()->string($field)->toString())])
                ->all(),
        ];
    }

    /**
     * The stored form of an already validated phone number: E.164, "+" and digits only.
     */
    public static function toE164(string $phone): string
    {
        return '+'.preg_replace('/\D/', '', $phone);
    }

    /**
     * @return list<UploadedFile>
     */
    public function proofDocuments(): array
    {
        return array_values($this->file('proof_documents', []));
    }

    private function emailIsUnavailable(string $email): bool
    {
        $hasOpenApplication = MembershipApplication::query()
            ->where('email', $email)
            ->whereIn('status', MembershipApplication::OPEN_STATUSES)
            ->exists();

        return $hasOpenApplication
            || User::query()->where('email', $email)->exists()
            || DB::table('memberships')
                ->join('membership_applications', 'membership_applications.id', '=', 'memberships.membership_application_id')
                ->where('membership_applications.email', $email)
                ->exists();
    }

    /**
     * A phone number must carry an explicit international country code and
     * reduce to a valid E.164 number once spaces and ( ) . - are removed. A
     * national trunk "0" written as its own group after the country code
     * (e.g. "+94 077…" or "+44 (0)20…") is ambiguous and rejected; Italy (+39),
     * whose numbers keep the 0, is the exception. Uniqueness is deliberately not checked
     * (docs/database/04: no matching rule applies to phone numbers).
     */
    private function internationalPhone(string $label): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($label): void {
            // A non-string (e.g. an array) is already rejected by the `string` rule.
            if (! is_string($value)) {
                return;
            }

            $value = trim($value);

            if (! str_starts_with($value, '+')) {
                $fail("Enter the {$label} with its international country code, starting with + (for example +94 77 123 4567).");

                return;
            }

            if (preg_match('/^\+[0-9\s().-]+$/', $value) !== 1
                || preg_match(self::E164_PATTERN, self::toE164($value)) !== 1) {
                $fail("Enter a valid {$label} in international format, for example +94 77 123 4567.");

                return;
            }

            if (preg_match('/^\+[0-9]{1,3}(?:[\s.-]+\(?0|[\s.-]*\(0)/', $value) === 1 && ! str_starts_with($value, '+39')) {
                $fail("Remove the leading 0 after the country code from the {$label} (for example +94 77 123 4567, not +94 077 123 4567).");
            }
        };
    }

    private function roleLabel(): string
    {
        return match ($this->input('category')) {
            MembershipCategory::CODE_STUDENT => 'course name',
            MembershipCategory::CODE_VETERAN => 'position held',
            MembershipCategory::CODE_CORPORATE => 'company type',
            MembershipCategory::CODE_SCHOOL_CLUB => 'aviation club name',
            default => 'occupation',
        };
    }

    private function organisationLabel(): string
    {
        return match ($this->input('category')) {
            MembershipCategory::CODE_STUDENT => 'training institute',
            MembershipCategory::CODE_VETERAN => 'most recent aviation employer',
            MembershipCategory::CODE_CORPORATE => 'representative position/designation',
            MembershipCategory::CODE_SCHOOL_CLUB => 'position',
            default => 'employer or organisation',
        };
    }
}
