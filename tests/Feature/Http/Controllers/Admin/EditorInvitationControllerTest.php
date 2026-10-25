<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Models\AccountSetupToken;
use App\Models\User;
use App\Notifications\Membership\AccountSetup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\MysqlTestCase;

class EditorInvitationControllerTest extends MysqlTestCase
{
    use CreatesReviewableApplications;

    private const EMAIL = 'new.editor@example.test';

    private function invite(User $admin, array $overrides = []): TestResponse
    {
        return $this->actingAs($admin)->post(route('admin.editor-invitations.store'), [
            'email' => self::EMAIL,
            'role' => 'editor',
            ...$overrides,
        ]);
    }

    /**
     * The plaintext token from the one invitation email sent to $user.
     */
    private function sentToken(User $user): string
    {
        $token = null;
        Notification::assertSentTo($user, AccountSetup::class, function (AccountSetup $notification) use (&$token): bool {
            $token = basename(parse_url($notification->setupUrl(), PHP_URL_PATH));

            return true;
        });

        return $token;
    }

    public function test_an_admin_invites_an_editor_who_sets_their_own_password_and_lands_on_the_editor_dashboard(): void
    {
        Notification::fake();
        $admin = $this->admin();

        $this->invite($admin)
            ->assertRedirect(route('admin.dashboard'))
            ->assertSessionHas('status', 'An editor invitation has been sent to '.self::EMAIL.'.');

        $editor = User::query()->where('email', self::EMAIL)->firstOrFail();
        $this->assertSame('editor', $editor->role);
        $this->assertSame('pending_setup', $editor->status);
        $this->assertNull(DB::table('users')->where('id', $editor->id)->value('password'));

        $token = AccountSetupToken::query()->where('user_id', $editor->id)->firstOrFail();
        $this->assertNull($token->membership_id);
        $this->assertSame($admin->id, $token->issued_by_user_id);
        $this->assertTrue($token->expires_at->isFuture());
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'user.editor_invited')->where('subject_id', $editor->id)->count());

        $plain = $this->sentToken($editor);
        $this->assertSame(AccountSetupToken::hashFor($plain), $token->token_hash, 'Only the hash is stored.');

        // The email is the editor wording, carries the link, and no password.
        $mail = (new AccountSetup($plain, $token->expires_at))->toMail($editor);
        $html = $mail->render();
        $this->assertSame('You have been invited to Aviation Club as an editor', $mail->subject);
        $this->assertStringContainsString('invited to join Aviation Club as an editor', $html);
        $this->assertStringContainsString('/account/setup/'.$plain, $html);
        $this->assertStringNotContainsString('membership is now active', $html);
        $this->assertDoesNotMatchRegularExpression('/(temporary|temp)\s+password|password\s*:/i', $html);

        // The inviting admin signs out; the editor cannot sign in before setup.
        $this->post(route('logout'));
        $this->assertGuest();
        $this->post(route('login'), ['email' => self::EMAIL, 'password' => 'Editor-Passw0rd-1'])->assertSessionHasErrors();
        $this->assertGuest();

        $this->post(route('account.setup.store', $plain), ['password' => 'Editor-Passw0rd-1', 'password_confirmation' => 'Editor-Passw0rd-1'])
            ->assertRedirect(route('login'));
        $this->assertSame('active', $editor->fresh()->status);
        $this->assertTrue(Hash::check('Editor-Passw0rd-1', DB::table('users')->where('id', $editor->id)->value('password')));

        $this->post(route('login'), ['email' => self::EMAIL, 'password' => 'Editor-Passw0rd-1'])
            ->assertRedirect(route('editor.dashboard'));
        $this->get(route('editor.dashboard'))->assertOk();
        $this->get(route('admin.dashboard'))->assertForbidden();
    }

    public function test_the_link_works_once_only(): void
    {
        Notification::fake();
        $this->invite($this->admin());
        $editor = User::query()->where('email', self::EMAIL)->firstOrFail();
        $plain = $this->sentToken($editor);

        $this->post(route('account.setup.store', $plain), ['password' => 'Editor-Passw0rd-1', 'password_confirmation' => 'Editor-Passw0rd-1']);

        $this->get(route('account.setup', $plain))->assertNotFound()->assertSee('Link no longer valid');
        $this->post(route('account.setup.store', $plain), ['password' => 'Another-Passw0rd-2', 'password_confirmation' => 'Another-Passw0rd-2'])->assertNotFound();
        $this->assertTrue(Hash::check('Editor-Passw0rd-1', DB::table('users')->where('id', $editor->id)->value('password')));
    }

    public function test_the_link_expires(): void
    {
        Notification::fake();
        $this->invite($this->admin());
        $editor = User::query()->where('email', self::EMAIL)->firstOrFail();
        $plain = $this->sentToken($editor);
        $ttl = (int) DB::table('membership_settings')->where('id', 1)->value('account_setup_token_ttl_hours');

        $this->travel($ttl + 1)->hours();

        $this->get(route('account.setup', $plain))->assertNotFound();
        $this->post(route('account.setup.store', $plain), ['password' => 'Editor-Passw0rd-1', 'password_confirmation' => 'Editor-Passw0rd-1'])->assertNotFound();
        $this->assertSame('pending_setup', $editor->fresh()->status);
    }

    public function test_re_inviting_a_pending_editor_issues_a_new_link_and_kills_the_old_one_without_a_second_account(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $this->invite($admin);
        $editor = User::query()->where('email', self::EMAIL)->firstOrFail();
        $first = $this->sentToken($editor);

        Notification::fake();
        $this->invite($admin, ['email' => 'New.Editor@Example.Test'])
            ->assertRedirect(route('admin.dashboard'))
            ->assertSessionHas('status', 'A new editor invitation link has been sent to '.self::EMAIL.'. The previous link no longer works.');
        $second = $this->sentToken($editor);

        $this->assertSame(1, User::query()->where('email', self::EMAIL)->count());
        $this->assertNotSame($first, $second);
        $this->get(route('account.setup', $first))->assertNotFound();
        $this->get(route('account.setup', $second))->assertOk();
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function existingAccounts(): array
    {
        return [
            'member' => [['role' => 'member', 'status' => 'active']],
            'active editor' => [['role' => 'editor', 'status' => 'active']],
            'admin' => [['role' => 'admin', 'status' => 'active']],
            'dev' => [['role' => 'dev', 'status' => 'active']],
            'pending member' => [['role' => 'member', 'status' => 'pending_setup']],
            'suspended editor' => [['role' => 'editor', 'status' => 'suspended']],
        ];
    }

    #[DataProvider('existingAccounts')]
    public function test_an_existing_account_is_refused_and_left_unchanged(array $attributes): void
    {
        Notification::fake();
        $existing = User::factory()->create(['email' => self::EMAIL, ...$attributes]);
        $before = DB::table('users')->where('id', $existing->id)->first();

        $this->invite($this->admin())
            ->assertRedirect(route('admin.dashboard'))
            ->assertSessionHasErrorsIn('invitation', ['email' => 'An account with this email address already exists. It has not been changed.']);

        $this->assertEquals($before, DB::table('users')->where('id', $existing->id)->first());
        $this->assertSame(0, AccountSetupToken::query()->where('user_id', $existing->id)->count());
        Notification::assertNothingSent();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function escalatedRoles(): array
    {
        return [
            'admin' => ['admin'],
            'dev' => ['dev'],
            'member' => ['member'],
            'unknown' => ['superadmin'],
        ];
    }

    #[DataProvider('escalatedRoles')]
    public function test_an_invitation_can_only_grant_the_editor_role(string $role): void
    {
        Notification::fake();

        $this->invite($this->admin(), ['role' => $role])
            ->assertSessionHasErrorsIn('invitation', ['role' => 'Invitations can only grant the Editor role.']);

        $this->assertDatabaseMissing('users', ['email' => self::EMAIL]);
        Notification::assertNothingSent();
    }

    public function test_invalid_or_missing_email_is_rejected(): void
    {
        $this->invite($this->admin(), ['email' => 'not-an-email'])->assertSessionHasErrorsIn('invitation', 'email');
        $this->invite($this->admin(), ['email' => ''])->assertSessionHasErrorsIn('invitation', 'email');
        $this->assertSame(0, User::query()->where('role', 'editor')->count());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonAdminRoles(): array
    {
        return ['editor' => ['editor'], 'dev' => ['dev'], 'member' => ['member']];
    }

    #[DataProvider('nonAdminRoles')]
    public function test_only_an_admin_can_invite(string $role): void
    {
        $user = User::factory()->active()->create(['role' => $role]);

        $this->actingAs($user)->post(route('admin.editor-invitations.store'), ['email' => self::EMAIL, 'role' => 'editor'])->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => self::EMAIL]);
    }

    public function test_guests_cannot_invite_and_the_csrf_token_is_required(): void
    {
        $this->post(route('admin.editor-invitations.store'), ['email' => self::EMAIL, 'role' => 'editor'])->assertRedirect(route('login'));
        $this->assertDatabaseMissing('users', ['email' => self::EMAIL]);
    }

    public function test_a_failed_email_keeps_the_invitation_and_tells_the_admin_to_resend(): void
    {
        Notification::shouldReceive('send')->andThrow(new \RuntimeException('SMTP down'));

        $this->invite($this->admin())
            ->assertRedirect(route('admin.dashboard'))
            ->assertSessionHas('warning', 'The editor invitation for '.self::EMAIL.' was created, but the email could not be sent. Invite the same address again to resend it.');

        $this->assertSame('pending_setup', User::query()->where('email', self::EMAIL)->value('status'));
    }

    public function test_public_registration_stays_disabled(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', ['email' => self::EMAIL, 'password' => 'x', 'password_confirmation' => 'x'])->assertNotFound();
        $this->assertDatabaseMissing('users', ['email' => self::EMAIL]);
    }
}
