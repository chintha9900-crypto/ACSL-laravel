<?php

namespace App\Http\Requests\Membership;

use App\Models\MembershipApplication;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class RespondToMoreDetailsRequest extends FormRequest
{
    /**
     * Access is decided by the signed link (the route's `signed` middleware), which is
     * tied to this application; a link for another application cannot be reused.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * A message, files, or both — the same upload rules as the original application.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $proof = config('uploads.aviation_proof');

        return [
            'response_message' => ['nullable', 'string', 'max:4000', 'required_without:response_documents'],
            'response_documents' => ['nullable', 'array', 'max:'.$proof['max_files'], 'required_without:response_message'],
            'response_documents.*' => ['required', File::types($proof['extensions'])->max($proof['max_kb'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $proof = config('uploads.aviation_proof');

        return [
            'response_message.required_without' => 'Write a reply or attach at least one document.',
            'response_documents.required_without' => 'Write a reply or attach at least one document.',
            'response_documents.max' => 'You can upload at most '.$proof['max_files'].' documents.',
            'response_documents.*.mimes' => 'Each document must be a PDF, JPG or PNG file.',
            'response_documents.*.max' => 'Each document must not be larger than '.round($proof['max_kb'] / 1024, 1).' MB.',
            'response_documents.*.uploaded' => 'A document could not be uploaded. Please check its size and try again.',
        ];
    }

    /**
     * Validation failures return to the applicant's own signed status page.
     */
    protected function getRedirectUrl(): string
    {
        /** @var MembershipApplication $application */
        $application = $this->route('application');

        return $application->signedUrl('applications.show', Carbon::createFromTimestamp((int) $this->query('expires')));
    }
}
