<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The General Inquiry form on the public Contact page. Phone is optional
 * (confirmed decision); subject is required (confirmed decision). The name
 * limit matches the documented `contact_enquiries.name` column (VARCHAR(150)).
 * Message's 5000-character limit matches the one already documented for this
 * form (docs/frontend/03_PUBLIC_PAGES.md §A14).
 *
 * The reCAPTCHA token is not validated here: it is verified server-side by
 * `Actions\Contact\VerifyRecaptchaToken` in the controller, so that
 * environment-dependent behaviour lives in one place.
 */
class StoreContactEnquiryRequest extends FormRequest
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
        return [
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40', 'regex:/^\+?[0-9][0-9\s().-]{5,38}[0-9]$/'],
            'subject' => ['required', 'string', 'max:200'],
            'message' => ['required', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => 'Enter a valid phone number, including the country code if outside Sri Lanka.',
        ];
    }
}
