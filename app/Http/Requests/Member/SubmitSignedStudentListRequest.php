<?php

namespace App\Http\Requests\Member;

use App\Models\Membership;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rules\File;

/**
 * The scanned student list, signed by the principal and stamped with the
 * school seal (PDF), that sends a School Club student list to admin review.
 */
class SubmitSignedStudentListRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $membership = $user === null ? null : Membership::query()->where('user_id', $user->id)->with('category')->first();

        return $membership !== null && $membership->isSchoolClubMain();
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'principal_list' => ['required', File::types(['pdf'])->max((int) config('uploads.aviation_proof.max_kb'))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'principal_list.required' => 'Please attach the student list signed by the principal (scanned PDF).',
            'principal_list.mimes' => 'The principal-signed student list must be a PDF file.',
            'principal_list.max' => 'The principal-signed student list must not be larger than '.round(((int) config('uploads.aviation_proof.max_kb')) / 1024, 1).' MB.',
        ];
    }

    public function principalList(): UploadedFile
    {
        return $this->file('principal_list');
    }
}
