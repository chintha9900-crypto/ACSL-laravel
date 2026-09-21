<?php

namespace Tests\Feature\Http\Controllers\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Tests\MysqlTestCase;

class PasswordResetLinkControllerTest extends MysqlTestCase
{
    private const GENERIC_MESSAGE = 'If an account exists for that email, a password reset link has been sent.';

    public function test_forgot_password_page_renders(): void
    {
        $this->get('/forgot-password')->assertOk()->assertSee('Forgot your password?');
    }

    public function test_active_user_is_sent_a_reset_link(): void
    {
        Notification::fake();
        $user = User::factory()->active()->create();

        $this->post('/forgot-password', ['email' => $user->email])
            ->assertSessionHas('status', self::GENERIC_MESSAGE);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_pending_setup_user_is_not_sent_a_reset_link_and_sees_the_same_message(): void
    {
        Notification::fake();
        $user = User::factory()->pendingSetup()->create();

        $this->post('/forgot-password', ['email' => $user->email])
            ->assertSessionHas('status', self::GENERIC_MESSAGE);

        Notification::assertNothingSent();
    }

    public function test_suspended_user_is_not_sent_a_reset_link(): void
    {
        Notification::fake();
        $user = User::factory()->suspended()->create();

        $this->post('/forgot-password', ['email' => $user->email])
            ->assertSessionHas('status', self::GENERIC_MESSAGE);

        Notification::assertNothingSent();
    }

    public function test_unknown_email_sees_the_same_message_and_nothing_is_sent(): void
    {
        Notification::fake();

        $this->post('/forgot-password', ['email' => 'nobody@example.test'])
            ->assertSessionHas('status', self::GENERIC_MESSAGE);

        Notification::assertNothingSent();
    }

    public function test_forgot_password_requires_a_valid_email(): void
    {
        $this->post('/forgot-password', ['email' => 'not-an-email'])->assertSessionHasErrors('email');
    }
}
