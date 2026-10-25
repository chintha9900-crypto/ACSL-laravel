<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rules\File;

/**
 * Bank-transfer reference and evidence for an e-shop order. Access to the
 * order itself is checked by the controller (AuthorizesOrderAccess).
 */
class SubmitOrderBankTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $evidence = config('uploads.payment_evidence');

        return [
            'reference' => ['required', 'string', 'max:191'],
            'evidence' => ['required', 'array', 'min:1', 'max:'.(int) config('payments.bank_transfer.max_evidence_files')],
            'evidence.*' => ['required', File::types($evidence['extensions'])->max($evidence['max_kb'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $maxMb = round(config('uploads.payment_evidence.max_kb') / 1024, 1);

        return [
            'evidence.required' => 'Attach your transfer evidence (a bank receipt or confirmation).',
            'evidence.max' => 'Attach no more than :max files.',
            'evidence.*.mimes' => 'Evidence must be a PDF, JPG or PNG file.',
            'evidence.*.max' => "Each evidence file must be {$maxMb} MB or smaller.",
        ];
    }

    /**
     * @return list<UploadedFile>
     */
    public function evidenceFiles(): array
    {
        return array_values($this->file('evidence', []));
    }
}
