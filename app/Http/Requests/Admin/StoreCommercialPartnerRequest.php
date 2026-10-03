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
            'description' => ['nullable', 'string'],
            'url' => ['nullable', 'string', 'max:255', 'url'],
            'display_order' => ['nullable', 'integer', 'min:0'],
            'logo' => ['nullable', File::types($image['extensions'])->max($image['max_kb'])],
        ];
    }
}
