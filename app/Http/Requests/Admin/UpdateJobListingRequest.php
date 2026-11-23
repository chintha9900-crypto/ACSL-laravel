<?php

namespace App\Http\Requests\Admin;

/**
 * Same fields and rules as creating, plus the optional "Remove current
 * logo" checkbox. Saving never changes the job's status.
 */
class UpdateJobListingRequest extends StoreJobListingRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('job')) ?? false;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'remove_company_logo' => ['nullable', 'boolean'],
        ];
    }
}
