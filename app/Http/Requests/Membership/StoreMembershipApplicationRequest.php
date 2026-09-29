<?php

namespace App\Http\Requests\Membership;

use App\Models\MembershipApplication;
use App\Models\MembershipCategory;
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
        $corporate = 'exclude_unless:category,'.MembershipCategory::CODE_CORPORATE;
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
            'email' => ['required', 'string', 'email', 'max:255'],
            'mobile' => ['required', 'string', 'max:40', 'regex:/^\+?[0-9][0-9\s().-]{5,38}[0-9]$/'],
            'address' => ['required', 'string', 'max:400'],
            'aviation_role' => ['required', 'string', 'max:160'],
            'aviation_organisation' => ['required', 'string', 'max:200'],
            'study_start_date' => [$student, 'required', 'date'],
            'expected_completion_date' => [$student, 'required', 'date', 'after_or_equal:study_start_date'],
            'years_experience' => [$veteran, 'required', 'integer', 'between:0,80'],
            'previous_employers' => [$veteran, 'required', 'string', 'max:2000'],
            'company_name' => [$corporate, 'required', 'string', 'max:160'],
            'company_email' => [$corporate, 'required', 'string', 'email', 'max:255'],
            'company_phone' => [$corporate, 'required', 'string', 'max:40', 'regex:/^\+?[0-9][0-9\s().-]{5,38}[0-9]$/'],
            'company_website' => [$corporate, 'nullable', 'string', 'url', 'max:255'],
            'proof_documents' => [$needsProof, 'required', 'array', 'min:1', 'max:'.$proof['max_files']],
            'proof_documents.*' => [
                $needsProof,
                'required',
                File::types($proof['extensions'])->max($proof['max_kb']),
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
            'mobile.regex' => 'Enter a valid mobile number, including the country code if outside Sri Lanka.',
            'company_phone.regex' => 'Enter a valid company phone number, including the country code if outside Sri Lanka.',
            'proof_documents.required' => 'Aviation eligibility proof is required. Please upload at least one document.',
            'proof_documents.min' => 'Aviation eligibility proof is required. Please upload at least one document.',
            'proof_documents.max' => 'You can upload at most '.$proof['max_files'].' documents.',
            'proof_documents.*.mimes' => 'Each document must be a PDF, JPG or PNG file.',
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
            'company_name' => 'company name',
            'company_email' => 'company email',
            'company_phone' => 'company phone',
            'company_website' => 'company website',
            'proof_documents' => $this->input('category') === MembershipCategory::CODE_CORPORATE
                ? 'company membership/request letter'
                : 'aviation proof',
        ];
    }

    /**
     * Duplicate protection: an open application, or an existing membership, for
     * this email (docs/database/15 R-06). A rejected application does not block a
     * new one. The database's unique `open_email_key` remains the backstop
     * against a concurrent duplicate.
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

                if ($this->hasOpenApplicationOrMembership($this->string('email')->toString())) {
                    $validator->errors()->add(
                        'email',
                        'An application or membership already exists for this email address. If you need help, please contact ACI.'
                    );
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
        ];
    }

    /**
     * @return list<UploadedFile>
     */
    public function proofDocuments(): array
    {
        return array_values($this->file('proof_documents', []));
    }

    private function hasOpenApplicationOrMembership(string $email): bool
    {
        $hasOpenApplication = MembershipApplication::query()
            ->where('email', $email)
            ->whereIn('status', MembershipApplication::OPEN_STATUSES)
            ->exists();

        return $hasOpenApplication || DB::table('memberships')
            ->join('membership_applications', 'membership_applications.id', '=', 'memberships.membership_application_id')
            ->where('membership_applications.email', $email)
            ->exists();
    }

    private function roleLabel(): string
    {
        return match ($this->input('category')) {
            MembershipCategory::CODE_STUDENT => 'course name',
            MembershipCategory::CODE_VETERAN => 'position held',
            MembershipCategory::CODE_CORPORATE => 'company type',
            default => 'occupation',
        };
    }

    private function organisationLabel(): string
    {
        return match ($this->input('category')) {
            MembershipCategory::CODE_STUDENT => 'training institute',
            MembershipCategory::CODE_VETERAN => 'most recent aviation employer',
            MembershipCategory::CODE_CORPORATE => 'representative position/designation',
            default => 'employer or organisation',
        };
    }
}
