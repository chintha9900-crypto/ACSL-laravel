<?php

namespace Tests\Feature\Http\Controllers\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\MysqlTestCase;

class NewPasswordControllerTest extends MysqlTestCase
{
    /**
     * @return array<string, string>
     */
    private function resetPayload(User $user, string $token, string $password = 'Brand-New-Passw0rd'): array
    {
        return [
            'token' => $token,
            'email' => $user->email,
            'password' => $password,
            'password_confirmation' => $password,
        ];
    }

    public function test_reset_page_renders_for_a_token(): void
    {
        $this->get('/reset-password/some-token?email=member@example.test')
            ->assertOk()
            ->assertSee('Choose a new password')
            ->assertSee('member@example.test');
    }

    public function test_valid_token_sets_a_hashed_password_and_can_only_be_used_once(): void
    {
        $user = User::factory()->active()->create();
        $token = Password::createToken($user);
        $oldRememberToken = $user->remember_token;

        $this->post('/reset-password', $this->resetPayload($user, $token))
            ->assertRedirect(route('login'));

        $stored = User::query()->whereKey($user->id)->value('password');
        $this->assertNotSame('Brand-New-Passw0rd', $stored);
        $this->assertTrue(Hash::check('Brand-New-Passw0rd', $stored));
        $this->assertNotSame($oldRememberToken, $user->fresh()->remember_token);

        $this->post('/reset-password', $this->resetPayload($user, $token, 'Another-Passw0rd-2'))
            ->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('Brand-New-Passw0rd', User::query()->whereKey($user->id)->value('password')));
    }

    public function test_invalid_token_is_rejected_and_the_password_is_unchanged(): void
    {
        $user = User::factory()->active()->create();

        $this->post('/reset-password', $this->resetPayload($user, 'forged-token'))
            ->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('password', User::query()->whereKey($user->id)->value('password')));
    }

    public function test_token_cannot_reset_a_pending_setup_account(): void
    {
        $user = User::factory()->pendingSetup()->create();
        $token = Password::createToken($user);

        $this->post('/reset-password', $this->resetPayload($user, $token))
            ->assertSessionHasErrors('email');

        $this->assertNull(User::query()->whereKey($user->id)->value('password'));
        $this->assertSame('pending_setup', $user->fresh()->status);
    }

    public function test_new_password_must_be_confirmed_and_meet_the_minimum_length(): void
    {
        $user = User::factory()->active()->create();
        $token = Password::createToken($user);

        $this->post('/reset-password', [...$this->resetPayload($user, $token), 'password_confirmation' => 'different'])
            ->assertSessionHasErrors('password');

        $this->post('/reset-password', $this->resetPayload($user, $token, 'short'))
            ->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('password', User::query()->whereKey($user->id)->value('password')));
    }
}
