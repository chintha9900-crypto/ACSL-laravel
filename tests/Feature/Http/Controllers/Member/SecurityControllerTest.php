<?php

namespace Tests\Feature\Http\Controllers\Member;

use App\Models\User;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\MysqlTestCase;

class SecurityControllerTest extends MysqlTestCase
{
    use CreatesReviewableApplications;

    private const NEW_PASSWORD = 'a-New-Strong-Password-1';

    protected function tearDown(): void
    {
        EncryptCookies::flushState();

        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'current_password' => 'password', // the plaintext behind User::factory()'s default hash.
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ], $overrides);
    }

    public function test_an_active_member_can_view_the_security_page(): void
    {
        $this->actingAs($this->member())
            ->get(route('member.security.show'))
            ->assertOk()
            ->assertSee('Current password')
            ->assertSee('New password')
            ->assertSee('Confirm new password');
    }

    public function test_a_guest_is_rejected(): void
    {
        $this->get(route('member.security.show'))->assertRedirect(route('login'));
        $this->patch(route('member.security.update'), $this->payload())->assertRedirect(route('login'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function notActiveStatuses(): array
    {
        return [
            'suspended' => ['suspended'],
            'pending setup' => ['pending_setup'],
        ];
    }

    #[DataProvider('notActiveStatuses')]
    public function test_a_member_whose_account_is_not_active_is_rejected(string $status): void
    {
        $user = $this->member();
        $user->forceFill(['status' => $status])->save();

        $this->actingAs($user)->get(route('member.security.show'))->assertRedirect(route('login'));
        $this->actingAs($user)->patch(route('member.security.update'), $this->payload())->assertRedirect(route('login'));
    }

    public function test_the_correct_current_password_allows_the_change(): void
    {
        $user = $this->member();

        $this->actingAs($user)
            ->patch(route('member.security.update'), $this->payload())
            ->assertRedirect(route('member.security.show'))
            ->assertSessionHas('status', 'Your password has been changed.');

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->fresh()->password));
    }

    public function test_an_incorrect_current_password_is_rejected(): void
    {
        $user = $this->member();
        $originalHash = $user->password;

        $this->actingAs($user)
            ->patch(route('member.security.update'), $this->payload(['current_password' => 'the-Wrong-Password-1']))
            ->assertSessionHasErrors('current_password');

        $this->assertSame($originalHash, $user->fresh()->password);
    }

    public function test_a_missing_current_password_is_rejected(): void
    {
        $user = $this->member();
        $payload = $this->payload();
        unset($payload['current_password']);

        $this->actingAs($user)
            ->patch(route('member.security.update'), $payload)
            ->assertSessionHasErrors('current_password');

        $this->assertSame($user->password, $user->fresh()->password);
    }

    public function test_the_new_password_must_be_confirmed(): void
    {
        $user = $this->member();

        $this->actingAs($user)
            ->patch(route('member.security.update'), $this->payload(['password_confirmation' => 'a-Different-Password-2']))
            ->assertSessionHasErrors('password');

        $this->assertSame($user->password, $user->fresh()->password);
    }

    public function test_password_rules_are_enforced(): void
    {
        $user = $this->member();

        $this->actingAs($user)
            ->patch(route('member.security.update'), $this->payload(['password' => 'short1', 'password_confirmation' => 'short1']))
            ->assertSessionHasErrors('password');

        $this->assertSame($user->password, $user->fresh()->password);
    }

    public function test_the_password_is_actually_changed_and_hashed(): void
    {
        $user = $this->member();

        $this->actingAs($user)->patch(route('member.security.update'), $this->payload());

        $stored = DB::table('users')->where('id', $user->id)->value('password');
        $this->assertNotSame(self::NEW_PASSWORD, $stored, 'The password must be hashed, never stored in plaintext.');
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $stored));
    }

    public function test_the_old_password_no_longer_works(): void
    {
        $user = $this->member();

        $this->actingAs($user)->patch(route('member.security.update'), $this->payload());
        Auth::logout();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post('/login', ['email' => $user->email, 'password' => self::NEW_PASSWORD])
            ->assertSessionDoesntHaveErrors();
    }

    public function test_remember_token_is_rotated(): void
    {
        $user = $this->member();
        $originalToken = $user->remember_token;

        $this->actingAs($user)->patch(route('member.security.update'), $this->payload());

        $newToken = $user->fresh()->remember_token;
        $this->assertNotSame($originalToken, $newToken);
        $this->assertNotNull($newToken);
        $this->assertSame(60, strlen($newToken));
    }

    public function test_the_session_id_is_regenerated_after_a_successful_change(): void
    {
        $sessionCookie = config('session.cookie');
        EncryptCookies::except($sessionCookie);
        $user = $this->member();

        $first = $this->actingAs($user)->get(route('member.security.show'));
        $firstId = $first->getCookie($sessionCookie, decrypt: false)?->getValue();
        $this->assertNotNull($firstId, 'The first request must establish a session.');

        $second = $this->actingAs($user)
            ->withCookie($sessionCookie, $firstId)
            ->patch(route('member.security.update'), $this->payload());

        $secondId = $second->getCookie($sessionCookie, decrypt: false)?->getValue();

        $this->assertNotNull($secondId);
        $this->assertNotSame($firstId, $secondId, 'The session id must be rotated by a successful password change.');
    }

    public function test_a_failed_change_alters_neither_the_password_nor_the_remember_token(): void
    {
        $user = $this->member();
        $originalHash = $user->password;
        $originalToken = $user->remember_token;

        $this->actingAs($user)
            ->patch(route('member.security.update'), $this->payload(['current_password' => 'wrong-password-here']))
            ->assertSessionHasErrors('current_password');

        $fresh = $user->fresh();
        $this->assertSame($originalHash, $fresh->password);
        $this->assertSame($originalToken, $fresh->remember_token);
    }

    public function test_another_users_account_cannot_be_targeted(): void
    {
        $me = $this->member();
        $someoneElse = $this->member();
        $before = DB::table('users')->where('id', $someoneElse->id)->first();

        $this->actingAs($me)
            ->patch(route('member.security.update'), $this->payload([
                'id' => $someoneElse->id,
                'user_id' => $someoneElse->id,
            ]))
            ->assertRedirect(route('member.security.show'));

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $me->fresh()->password), 'Only the acting member changes.');
        $this->assertEquals($before, DB::table('users')->where('id', $someoneElse->id)->first(), 'The other member is untouched.');
    }

    public function test_protected_fields_cannot_be_modified_through_request_input(): void
    {
        $user = $this->member()->fresh();
        $originalEmail = $user->email;
        $originalRole = $user->role;
        $originalStatus = $user->status;
        $originalVerifiedAt = $user->email_verified_at;

        $this->actingAs($user)
            ->patch(route('member.security.update'), $this->payload([
                'email' => 'hijacked@example.test',
                'role' => 'admin',
                'status' => 'suspended',
                'email_verified_at' => null,
            ]))
            ->assertRedirect(route('member.security.show'));

        $fresh = $user->fresh();
        $this->assertSame($originalEmail, $fresh->email);
        $this->assertSame($originalRole, $fresh->role);
        $this->assertSame($originalStatus, $fresh->status);
        $this->assertEquals($originalVerifiedAt, $fresh->email_verified_at);
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $fresh->password), 'The legitimate password change in the same request still applies.');
    }

    public function test_the_update_route_is_throttled(): void
    {
        $user = $this->member();
        $this->actingAs($user);

        foreach (range(1, 6) as $attempt) {
            $this->patch(route('member.security.update'), $this->payload(['current_password' => 'wrong-password']))
                ->assertSessionHasErrors('current_password');
        }

        $this->patch(route('member.security.update'), $this->payload(['current_password' => 'wrong-password']))
            ->assertStatus(429);
    }

    public function test_password_values_never_appear_in_response_session_or_log_output(): void
    {
        Log::spy();
        $user = $this->member();
        $wrongPassword = 'a-Traceable-Wrong-Password-9';
        $newPassword = 'a-Traceable-New-Password-9';

        $this->actingAs($user)
            ->patch(route('member.security.update'), $this->payload([
                'current_password' => $wrongPassword,
                'password' => $newPassword,
                'password_confirmation' => $newPassword,
            ]))
            ->assertSessionHasErrors('current_password');

        // Laravel's default $dontFlash (current_password, password, password_confirmation)
        // must keep these out of the session's flashed old input.
        $oldInput = session('_old_input', []);
        $this->assertArrayNotHasKey('current_password', $oldInput);
        $this->assertArrayNotHasKey('password', $oldInput);
        $this->assertArrayNotHasKey('password_confirmation', $oldInput);

        $this->get(route('member.security.show'))
            ->assertDontSee($wrongPassword)
            ->assertDontSee($newPassword);

        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');
    }
}
