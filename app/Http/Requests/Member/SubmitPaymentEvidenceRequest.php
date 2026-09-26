<?php

namespace App\Http\Requests\Member;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class SubmitPaymentEvidenceRequest extends FormRequest
{
    /**
     * The target payment is resolved from the authenticated member's own
     * membership, never from a submitted id — nothing here to authorize
     * against a route parameter (docs/architecture/14 §3).
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $evidence = config('uploads.payment_evidence');

        return [
            'reference' => ['required', 'string', 'max:191'],
            'evidence' => ['required', File::types($evidence['extensions'])->max($evidence['max_kb'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'evidence.required' => 'Attach your payment evidence (a receipt or bank confirmation).',
        ];
    }
}
