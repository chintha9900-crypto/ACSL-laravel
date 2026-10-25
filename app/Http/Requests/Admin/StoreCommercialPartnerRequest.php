<?php

namespace App\Http\Requests\Admin;

use App\Models\CommercialPartner;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class StoreCommercialPartnerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', CommercialPartner::class) ?? false;
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
            'logo' => ['required', File::image()->types($image['extensions'])->max($image['max_kb'])],
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
