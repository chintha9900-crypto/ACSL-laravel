<?php

namespace Tests\Feature\Http\Middleware;

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Tests\MysqlTestCase;

class EnsureUserIsActiveTest extends MysqlTestCase
{
    private function registerProtectedRoute(): void
    {
        Route::middleware(['web', 'auth', 'active'])->get('/_protected', fn () => 'members only');
    }

    public function test_active_user_reaches_the_route(): void
    {
        $this->registerProtectedRoute();

        $this->actingAs(User::factory()->active()->create())
            ->get('/_protected')
            ->assertOk();
    }

    public function test_user_suspended_after_signing_in_is_signed_out_on_the_next_request(): void
    {
        $this->registerProtectedRoute();
        $user = User::factory()->active()->create();

        $this->actingAs($user);
        $user->forceFill(['status' => 'suspended'])->save();

        $this->get('/_protected')->assertRedirect(route('login'));

        $this->assertGuest();
    }
}
