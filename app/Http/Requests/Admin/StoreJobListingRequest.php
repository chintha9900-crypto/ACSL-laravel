<?php

namespace App\Http\Requests\Admin;

use App\Models\JobListing;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

/**
 * Create a job listing (always saved as a draft). The application URL and a
 * current deadline are optional here and only required when publishing
 * (PublishJobListing), so a draft can be saved while details are pending.
 * Status, published_at and the slug are never accepted from the form.
 */
class StoreJobListingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', JobListing::class) ?? false;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $logo = config('uploads.job_logo');

        return [
            'title' => ['required', 'string', 'max:255'],
            'company_name' => ['required', 'string', 'max:255'],
            'company_logo' => ['nullable', File::types($logo['extensions'])->max($logo['max_kb'])],
            'category' => ['required', 'string', Rule::in(array_keys(JobListing::CATEGORIES))],
            'employment_type' => ['required', 'string', Rule::in(array_keys(JobListing::EMPLOYMENT_TYPES))],
            'location_type' => ['required', 'string', Rule::in(array_keys(JobListing::LOCATION_TYPES))],
            'location' => ['required', 'string', 'max:255'],
            'country' => ['required', 'string', 'max:100'],
            'short_description' => ['required', 'string', 'max:500'],
            'description' => ['required', 'string', 'max:100000'],
            'responsibilities' => ['nullable', 'string', 'max:100000'],
            'qualifications' => ['nullable', 'string', 'max:100000'],
            'experience' => ['nullable', 'string', 'max:100000'],
            'additional_requirements' => ['nullable', 'string', 'max:100000'],
            'salary' => ['nullable', 'string', 'max:255'],
            'date_posted' => ['nullable', 'date_format:Y-m-d'],
            'application_deadline' => ['required', 'date_format:Y-m-d'],
            'application_url' => ['nullable', ...self::applicationUrlRules()],
        ];
    }

    /**
     * An http(s) URL with a real hostname. Laravel's `url` rule checks the
     * overall format and scheme; the closure additionally rejects anything
     * whose parsed scheme or host is missing, so `javascript:`, `data:`,
     * `ftp:` and malformed values are all refused. Shared with publishing.
     *
     * @return list<mixed>
     */
    public static function applicationUrlRules(): array
    {
        return [
            'string',
            'max:2048',
            'url:http,https',
            function (string $attribute, mixed $value, Closure $fail): void {
                $parts = parse_url((string) $value);
                $scheme = strtolower((string) ($parts['scheme'] ?? ''));
                $host = (string) ($parts['host'] ?? '');

                if (! in_array($scheme, ['http', 'https'], true) || $host === '' || ! str_contains($host, '.')) {
                    $fail('The application URL must be a full web address starting with http:// or https://.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'application_url.url' => 'The application URL must be a full web address starting with http:// or https://.',
            'application_deadline.date_format' => 'Enter the deadline as a date.',
        ];
    }
}
