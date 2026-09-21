<?php

namespace Tests\Feature\Http\Controllers\Auth;

use App\Actions\Auth\IssueAccountSetupToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesMembershipRecords;
use Tests\MysqlTestCase;

class AccountSetupControllerTest extends MysqlTestCase
{
    use CreatesMembershipRecords;

    /**
     * @return array{User, string}
     */
    private function provisionUserWithToken(): array
    {
        $user = User::factory()->pendingSetup()->create();
        $plain = app(IssueAccountSetupToken::class)->handle($user, $this->createMembershipFor($user));

        return [$user, $plain];
    }

    /**
     * @return array<string, string>
     */
    private function passwordPayload(string $password = 'My-First-Passw0rd'): array
    {
        return ['password' => $password, 'password_confirmation' => $password];
    }

    public function test_valid_link_shows_the_password_form_without_leaking_the_token(): void
    {
        [, $plain] = $this->provisionUserWithToken();

        $this->get("/account/setup/{$plain}")
            ->assertOk()
            ->assertSee('Create your password')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_setting_a_password_activates_the_account_and_redirects_to_login(): void
    {
        [$user, $plain] = $this->provisionUserWithToken();

        $this->post("/account/setup/{$plain}", $this->passwordPayload())
            ->assertRedirect(route('login'))
            ->assertSessionHas('status');

        $this->assertSame('active', $user->fresh()->status);
        $this->assertTrue(Hash::check('My-First-Passw0rd', DB::table('users')->where('id', $user->id)->value('password')));
    }

    public function test_member_cannot_sign_in_before_setup_but_can_after(): void
    {
        [$user, $plain] = $this->provisionUserWithToken();

        $this->post('/login', ['email' => $user->email, 'password' => 'My-First-Passw0rd']);
        $this->assertGuest();

        $this->post("/account/setup/{$plain}", $this->passwordPayload());
        $this->post('/login', ['email' => $user->email, 'password' => 'My-First-Passw0rd']);

        $this->assertAuthenticatedAs($user);
    }

    public function test_used_link_shows_the_generic_page_and_cannot_be_submitted_again(): void
    {
        [$user, $plain] = $this->provisionUserWithToken();
        $this->post("/account/setup/{$plain}", $this->passwordPayload());

        $this->get("/account/setup/{$plain}")
            ->assertNotFound()
            ->assertSee('This link is no longer valid');

        $this->post("/account/setup/{$plain}", $this->passwordPayload('An-Attackers-Passw0rd'))
            ->assertNotFound()
            ->assertSee('This link is no longer valid');

        $this->assertTrue(Hash::check('My-First-Passw0rd', DB::table('users')->where('id', $user->id)->value('password')));
    }

    public function test_expired_link_shows_the_generic_page_and_changes_nothing(): void
    {
        [$user, $plain] = $this->provisionUserWithToken();

        $this->travel(73)->hours();

        $this->get("/account/setup/{$plain}")->assertNotFound()->assertSee('This link is no longer valid');
        $this->post("/account/setup/{$plain}", $this->passwordPayload())->assertNotFound();

        $this->assertNull(DB::table('users')->where('id', $user->id)->value('password'));
        $this->assertSame('pending_setup', $user->fresh()->status);
    }

    public function test_unknown_link_shows_the_same_generic_page_as_an_expired_one(): void
    {
        $unknown = $this->get('/account/setup/'.str_repeat('x', 64));

        [, $plain] = $this->provisionUserWithToken();
        $this->travel(73)->hours();
        $expired = $this->get("/account/setup/{$plain}");

        $this->assertSame($unknown->status(), $expired->status());
        $this->assertSame(
            $this->normalise($unknown->getContent()),
            $this->normalise($expired->getContent()),
            'Unknown and expired links must be indistinguishable.'
        );
    }

    public function test_weak_or_unconfirmed_password_is_rejected_and_the_token_stays_usable(): void
    {
        [$user, $plain] = $this->provisionUserWithToken();

        $this->post("/account/setup/{$plain}", ['password' => 'short', 'password_confirmation' => 'short'])
            ->assertSessionHasErrors('password');
        $this->post("/account/setup/{$plain}", ['password' => 'My-First-Passw0rd', 'password_confirmation' => 'different'])
            ->assertSessionHasErrors('password');
        $this->post("/account/setup/{$plain}", [])->assertSessionHasErrors('password');

        $this->assertSame('pending_setup', $user->fresh()->status);
        $this->post("/account/setup/{$plain}", $this->passwordPayload())->assertRedirect(route('login'));
    }

    private function normalise(string $html): string
    {
        return preg_replace('/(name="_token" value=")[^"]+/', '$1', $html) ?? $html;
    }
}
