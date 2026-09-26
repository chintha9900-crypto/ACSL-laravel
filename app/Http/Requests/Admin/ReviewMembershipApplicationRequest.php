<?php

namespace App\Http\Requests\Admin;

use App\Actions\Membership\ReviewMembershipApplication;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewMembershipApplicationRequest extends FormRequest
{
    /**
     * Authorization is answered before validation (docs/architecture/14 §3).
     */
    public function authorize(): bool
    {
        return $this->user()?->can('review', $this->route('application')) ?? false;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', Rule::in(array_keys(ReviewMembershipApplication::DECISION_EVENTS))],
            'note' => ['nullable', 'string', 'max:2000'],
            'request_message' => ['exclude_unless:decision,more_details_required', 'required', 'string', 'max:2000'],
            'proof_reviewed' => ['exclude_unless:decision,approved', 'accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'decision.required' => 'Choose a decision.',
            'decision.in' => 'Choose one of the available decisions.',
            'request_message.required' => 'Write the message that tells the applicant what to provide.',
            'proof_reviewed.accepted' => 'Confirm that you have reviewed the aviation proof before approving.',
        ];
    }
}
