<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    /**
     * Login is available to guests; the route's `guest` middleware gates it.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * The credentials handed to the session guard.
     *
     * Only an `active` user can sign in: `pending_setup` accounts must complete
     * account setup first and `suspended` accounts are refused.
     *
     * @return array{email: string, password: string, status: string}
     */
    public function credentials(): array
    {
        return [
            'email' => $this->string('email')->toString(),
            'password' => $this->string('password')->toString(),
            'status' => 'active',
        ];
    }
}
