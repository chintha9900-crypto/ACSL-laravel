<?php

namespace App\Http\Requests\Member;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class UpdateProfileRequest extends FormRequest
{
    /**
     * The only fields a member may ever change about their own account. Deliberately
     * excludes email, role, status, password and anything membership-related — none
     * of those are declared here, so none of them can reach `validated()`/`safe()`
     * no matter what the request body contains.
     *
     * @var list<string>
     */
    public const EDITABLE_FIELDS = [
        'name', 'phone', 'country', 'aviation_occupation', 'job_title', 'company', 'linkedin_url', 'bio',
    ];

    /**
     * Authorization is answered before validation (docs/architecture/14 §3). The
     * target is always the signed-in user themself — there is no route parameter
     * to substitute another account's id into.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->user()) ?? false;
    }

    /**
     * Blank optional fields are treated as "clear this field", not as a value to
     * validate (an empty string would otherwise fail the `url` rule on LinkedIn).
     */
    protected function prepareForValidation(): void
    {
        $optional = array_diff(self::EDITABLE_FIELDS, ['name']);

        $this->merge(
            collect($this->only($optional))
                ->map(fn (mixed $value) => is_string($value) && trim($value) === '' ? null : $value)
                ->all()
        );
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $avatar = config('uploads.avatar');

        return [
            'name' => ['required', 'string', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40', 'regex:/^\+?[0-9][0-9\s().-]{5,38}[0-9]$/'],
            'country' => ['nullable', 'string', 'max:100'],
            'aviation_occupation' => ['nullable', 'string', 'max:150'],
            'job_title' => ['nullable', 'string', 'max:150'],
            'company' => ['nullable', 'string', 'max:150'],
            'linkedin_url' => ['nullable', 'string', 'max:255', 'url'],
            'bio' => ['nullable', 'string', 'max:2000'],
            'avatar' => ['nullable', File::types($avatar['extensions'])->max($avatar['max_kb'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => 'Enter a valid phone number, including the country code if outside Sri Lanka.',
            'linkedin_url.url' => 'Enter a full LinkedIn URL, including https://.',
        ];
    }
}
