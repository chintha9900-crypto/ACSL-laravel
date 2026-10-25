<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class UpdateCommercialPartnerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('partner')) ?? false;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $image = config('uploads.commercial_partner_logo');

        return [
            'name' => ['required', 'string', 'max:255'],
            'url' => ['required', 'string', 'max:255', 'url:http,https'],
            // Required only when the partner has no logo yet; otherwise the
            // current logo is kept unless a new one is uploaded.
            'logo' => [$this->route('partner')?->logo_path ? 'nullable' : 'required', File::image()->types($image['extensions'])->max($image['max_kb'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'logo.required' => 'Upload the partner logo (JPG or PNG).',
            'url.required' => 'Enter the company website address.',
            'url.url' => 'Enter a full website address starting with http:// or https://, e.g. https://example.com.',
        ];
    }
}
