<?php

namespace App\Http\Requests\Admin;

use App\Models\SchoolClubStudentBatch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewStudentBatchRequest extends FormRequest
{
    /**
     * Deciding needs `review`; approving (which creates memberships) also `approve`.
     */
    public function authorize(): bool
    {
        $batch = $this->route('batch');
        $user = $this->user();

        if ($user === null || ! $user->can('review', $batch)) {
            return false;
        }

        return $this->input('decision') !== SchoolClubStudentBatch::STATUS_APPROVED || $user->can('approve', $batch);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', Rule::in([
                SchoolClubStudentBatch::STATUS_APPROVED,
                SchoolClubStudentBatch::STATUS_MORE_DETAILS_REQUIRED,
                SchoolClubStudentBatch::STATUS_REJECTED,
            ])],
            'note' => ['nullable', 'string', 'max:2000'],
            'request_message' => ['exclude_unless:decision,more_details_required', 'required', 'string', 'max:2000'],
            'list_reviewed' => ['exclude_unless:decision,approved', 'accepted'],
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
            'request_message.required' => 'Write the message that tells the school what to provide.',
            'list_reviewed.accepted' => 'Confirm that you have checked the principal-signed student list before approving.',
        ];
    }
}
