<?php

namespace App\Actions\Auth;

use App\Exceptions\EditorInvitationNotAllowedException;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;

class InviteEditor
{
    public function __construct(private IssueAccountSetupToken $issueSetupToken) {}

    /**
     * Invite an editor: provision a `pending_setup` editor account with no
     * password and issue the existing one-time account setup token, so the
     * editor chooses their own password. No password is ever generated or sent.
     *
     * Re-inviting an editor who has not finished setup issues a fresh link and
     * invalidates the old one (the token action supersedes it) — it never
     * creates a second account. Any other existing account, active or not, is
     * refused: an invitation never changes an existing user's role.
     *
     * Returns the user and the plaintext token, for delivery after commit only.
     *
     * @return array{user: User, token: string, reinvited: bool}
     *
     * @throws EditorInvitationNotAllowedException
     */
    public function handle(string $email, User $admin): array
    {
        $email = Str::lower(trim($email));

        try {
            return DB::transaction(function () use ($email, $admin): array {
                $existing = User::query()->where('email', $email)->lockForUpdate()->first();

                if ($existing !== null && ! ($existing->role === Role::EDITOR && $existing->status === 'pending_setup')) {
                    throw new EditorInvitationNotAllowedException('An account with this email address already exists. It has not been changed.');
                }

                $user = $existing ?? User::forceCreate([
                    'name' => Str::before($email, '@'),
                    'email' => $email,
                    'password' => null,
                    'role' => Role::EDITOR,
                    'status' => 'pending_setup',
                ]);

                $token = $this->issueSetupToken->handle($user, null, $admin);

                DB::table('audit_logs')->insert([
                    'user_id' => $admin->id,
                    'actor_type' => 'user',
                    'event' => $existing === null ? 'user.editor_invited' : 'user.editor_reinvited',
                    'subject_type' => 'user',
                    'subject_id' => $user->id,
                    'new_values' => json_encode(['role' => Role::EDITOR, 'status' => 'pending_setup']),
                    'ip_address' => Request::ip(),
                    'user_agent' => Str::limit((string) Request::userAgent(), 512, ''),
                    'created_at' => now(),
                ]);

                return ['user' => $user, 'token' => $token, 'reinvited' => $existing !== null];
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw new EditorInvitationNotAllowedException('An account with this email address already exists. It has not been changed.', previous: $exception);
        }
    }
}
