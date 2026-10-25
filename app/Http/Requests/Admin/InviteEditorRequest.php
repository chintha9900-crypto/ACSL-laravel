<?php

namespace App\Http\Requests\Admin;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InviteEditorRequest extends FormRequest
{
    /**
     * Keeps invitation errors apart from any other form on the admin dashboard.
     *
     * @var string
     */
    protected $errorBag = 'invitation';

    /**
     * Admin only (`users.manage`), enforced server-side by `UserPolicy::invite`.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('invite', User::class) ?? false;
    }

    /**
     * Only the editor role can be granted through an invitation, whatever the
     * submitted form contains — no route to admin/dev/member by invitation.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc,filter_unicode', 'max:255'],
            'role' => ['required', 'string', Rule::in([Role::EDITOR])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'role.in' => 'Invitations can only grant the Editor role.',
        ];
    }
}
