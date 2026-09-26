<?php

namespace Tests\Feature\Http\Controllers\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\MysqlTestCase;

class AuthenticatedSessionControllerTest extends MysqlTestCase
{
    private const FAILED_MESSAGE = 'These credentials do not match our records.';

    private function registerProtectedRoute(): void
    {
        Route::middleware(['web', 'auth', 'active'])->get('/_protected', fn () => 'members only');
    }

    public function test_login_page_renders_without_a_registration_link(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Sign in')
            ->assertDontSee('Create account')
            ->assertDontSee('Register');
    }

    /**
     * The normal email/password form, for a guest — not just any 200. Covers
     * every control named in the "can't see the login form" report: email
     * input, password input, Sign In button, Forgot Password link.
     */
    public function test_a_guest_sees_the_email_and_password_login_form(): void
    {
        $response = $this->get('/login')->assertOk();

        $response->assertSee('method="POST"', false);
        $response->assertSee('action="'.route('login').'"', false);
        $response->assertSee('type="email"', false);
        $response->assertSee('name="email"', false);
        $response->assertSee('type="password"', false);
        $response->assertSee('name="password"', false);
        $response->assertSee('type="submit"', false);
        $response->assertSee('Sign in');
        $response->assertSee('href="'.route('password.request').'"', false);
        $response->assertSee('Forgot?');
    }

    /**
     * The exact behaviour behind "/login doesn't show the form when I visit
     * it directly": the `guest` middleware redirects an already-authenticated
     * visitor away (to `/`, bootstrap/app.php's `redirectUsersTo`), by design
     * — it is not shown a second sign-in form. This is not a bug; logging
     * out (or clearing the session cookie) restores the guest experience.
     */
    public function test_an_already_authenticated_user_is_redirected_away_from_login(): void
    {
        $user = User::factory()->active()->create();

        $this->actingAs($user)->get('/login')->assertRedirect('/');
    }

    public function test_guest_is_redirected_to_login_from_a_protected_route(): void
    {
        $this->registerProtectedRoute();

        $this->get('/_protected')->assertRedirect(route('login'));
    }

    public function test_valid_credentials_sign_the_user_in_and_unlock_the_protected_route(): void
    {
        $this->registerProtectedRoute();
        $user = User::factory()->active()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
        $this->get('/_protected')->assertOk()->assertSee('members only');
    }

    public function test_login_returns_the_user_to_the_page_they_originally_requested(): void
    {
        $this->registerProtectedRoute();
        $user = User::factory()->active()->create();

        $this->get('/_protected')->assertRedirect(route('login'));
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('/_protected');
    }

    public function test_wrong_password_is_rejected_with_a_generic_message(): void
    {
        $user = User::factory()->active()->create();

        $this->from('/login')
            ->post('/login', ['email' => $user->email, 'password' => 'not-the-password'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => self::FAILED_MESSAGE]);

        $this->assertGuest();
    }

    public function test_unknown_email_gets_the_same_message_as_a_wrong_password(): void
    {
        $this->from('/login')
            ->post('/login', ['email' => 'nobody@example.test', 'password' => 'whatever-123'])
            ->assertSessionHasErrors(['email' => self::FAILED_MESSAGE]);

        $this->assertGuest();
    }

    public function test_active_account_with_a_null_password_cannot_sign_in(): void
    {
        $user = User::factory()->active()->create(['password' => null]);

        $this->assertNull($user->fresh()->password);

        foreach (['', 'password', 'null', '0'] as $attempt) {
            $this->post('/login', ['email' => $user->email, 'password' => $attempt]);

            $this->assertGuest();
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonActiveStatuses(): array
    {
        return [
            'pending setup' => ['pending_setup'],
            'suspended' => ['suspended'],
        ];
    }

    #[DataProvider('nonActiveStatuses')]
    public function test_non_active_account_cannot_sign_in_even_with_the_right_password(string $status): void
    {
        $user = User::factory()->create(['status' => $status]);

        $this->from('/login')
            ->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors(['email' => self::FAILED_MESSAGE]);

        $this->assertGuest();
    }

    public function test_login_requires_an_email_and_a_password(): void
    {
        $this->post('/login', [])->assertSessionHasErrors(['email', 'password']);
    }

    public function test_login_is_throttled_after_five_attempts_for_the_same_email_and_ip(): void
    {
        $user = User::factory()->active()->create();

        foreach (range(1, 5) as $attempt) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])
                ->assertSessionHasErrors('email');
        }

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertTooManyRequests();

        $this->assertGuest();
    }

    public function test_signed_in_user_is_redirected_away_from_the_login_page(): void
    {
        $this->actingAs(User::factory()->active()->create())
            ->get('/login')
            ->assertRedirect('/');
    }

    public function test_logout_ends_the_session_and_protected_routes_are_locked_again(): void
    {
        $this->registerProtectedRoute();
        $user = User::factory()->active()->create();

        $this->actingAs($user)->post('/logout')->assertRedirect('/');

        $this->assertGuest();
        $this->get('/_protected')->assertRedirect(route('login'));
    }

    public function test_logout_requires_authentication(): void
    {
        $this->post('/logout')->assertRedirect(route('login'));
    }

    public function test_password_hash_is_never_the_plaintext_and_login_does_not_rewrite_it(): void
    {
        $user = User::factory()->active()->create(['password' => 'a-Plain-Text-Secret-1']);

        $stored = User::query()->whereKey($user->id)->value('password');

        $this->assertNotSame('a-Plain-Text-Secret-1', $stored);
        $this->assertTrue(Hash::check('a-Plain-Text-Secret-1', $stored));

        $this->post('/login', ['email' => $user->email, 'password' => 'a-Plain-Text-Secret-1'])->assertRedirect('/');

        $this->assertNotSame('a-Plain-Text-Secret-1', User::query()->whereKey($user->id)->value('password'));
    }
}
