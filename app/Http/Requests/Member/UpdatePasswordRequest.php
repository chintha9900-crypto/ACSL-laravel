<?php

namespace App\Http\Requests\Member;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class UpdatePasswordRequest extends FormRequest
{
    /**
     * Authorization is answered before validation (docs/architecture/14 §3). The
     * target is always the signed-in user themself — there is no route parameter
     * to substitute another account's id into.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->user()) ?? false;
    }

    /**
     * Only ever a current password and a new one. Deliberately excludes email,
     * role, status, remember_token, email_verified_at and everything else — none
     * of those are declared here, so none of them can reach `validated()`/`safe()`
     * no matter what the request body contains.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'current_password:web'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_password.current_password' => 'The current password is incorrect.',
        ];
    }
}
