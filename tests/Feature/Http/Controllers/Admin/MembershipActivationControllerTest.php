<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Actions\Auth\CompleteAccountSetup;
use App\Models\User;
use App\Notifications\Membership\AccountSetup;
use App\Notifications\Membership\Welcome;
use Carbon\Carbon;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\Concerns\InspectsSetupLinks;
use Tests\MysqlTestCase;

class MembershipActivationControllerTest extends MysqlTestCase
{
    use CreatesReviewableApplications;
    use InspectsSetupLinks;

    public function test_the_activate_action_is_offered_only_for_an_approved_application_without_a_membership(): void
    {
        $approved = $this->application('approved', ['email' => 'a@example.test']);
        $submitted = $this->application(attributes: ['email' => 'b@example.test']);
        $rejected = $this->application('rejected', ['email' => 'c@example.test']);
        $this->actingAs($this->admin());

        $this->get(route('admin.membership-applications.show', $approved))
            ->assertSee('Activate membership')
            ->assertSee('name="confirm"', false)
            ->assertSee('I confirm that this membership should be activated now.');

        foreach ([$submitted, $rejected] as $application) {
            $this->get(route('admin.membership-applications.show', $application))->assertDontSee('Activate membership');
        }
    }

    public function test_confirming_activates_the_membership_and_shows_the_number_and_date(): void
    {
        $this->travelTo(Carbon::parse('2026-09-21 06:00:00', 'UTC'));
        $application = $this->application('approved', ['full_name' => 'Nimal Perera']);
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('admin.membership-applications.activate', $application), ['confirm' => '1'])
            ->assertRedirect(route('admin.membership-applications.show', $application))
            ->assertSessionHas('status');

        $membership = DB::table('memberships')->first();
        $this->assertMatchesRegularExpression('/^P26[0-9]{2}0001$/', $membership->membership_number);

        $this->get(route('admin.membership-applications.show', $application))
            ->assertOk()
            ->assertSee($membership->membership_number)
            ->assertSee('21 September 2026')
            ->assertSee('6 months, free')
            ->assertSee('20 Mar 2027')
            ->assertDontSee('Activate membership');

        $this->assertSame($admin->id, DB::table('audit_logs')->where('event', 'membership.activated')->value('user_id'));
    }

    public function test_the_confirmation_box_is_required(): void
    {
        $application = $this->application('approved');

        $this->actingAs($this->admin())
            ->post(route('admin.membership-applications.activate', $application), [])
            ->assertSessionHasErrors('confirm');

        $this->assertSame(0, DB::table('memberships')->count());
        $this->assertSame(0, DB::table('membership_number_sequences')->count());
    }

    public function test_a_double_submission_or_stale_page_activates_only_once(): void
    {
        $application = $this->application('approved');
        $this->actingAs($this->admin());

        $this->post(route('admin.membership-applications.activate', $application), ['confirm' => '1'])->assertSessionHasNoErrors();
        $this->post(route('admin.membership-applications.activate', $application), ['confirm' => '1'])
            ->assertRedirect(route('admin.membership-applications.show', $application))
            ->assertSessionHasErrors(['activation' => 'This application has already been activated.']);

        $this->assertSame(1, DB::table('memberships')->count());
        $this->assertSame(1, DB::table('membership_terms')->count());
        $this->assertSame(1, DB::table('membership_number_sequences')->value('last_number'));
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'membership.activated')->count());
    }

    public function test_an_application_that_is_not_approved_cannot_be_activated_through_the_form(): void
    {
        $application = $this->application();

        $this->actingAs($this->admin())
            ->post(route('admin.membership-applications.activate', $application), ['confirm' => '1'])
            ->assertSessionHasErrors('activation');

        $this->assertSame(0, DB::table('memberships')->count());
    }

    public function test_an_email_collision_is_reported_and_nothing_changes(): void
    {
        User::factory()->active()->create(['email' => 'taken@example.test']);
        $application = $this->application('approved', ['email' => 'taken@example.test']);
        $users = DB::table('users')->count();

        $this->actingAs($this->admin())
            ->post(route('admin.membership-applications.activate', $application), ['confirm' => '1'])
            ->assertSessionHasErrors('activation');

        $this->get(route('admin.membership-applications.show', $application))->assertSee('A user with this email address already exists');
        $this->assertSame($users + 1, DB::table('users')->count(), 'Only the admin created by this test; no member user.');
        $this->assertSame(0, DB::table('memberships')->count());
    }

    public function test_guests_and_non_admins_cannot_activate(): void
    {
        $application = $this->application('approved');
        $url = route('admin.membership-applications.activate', $application);

        $this->post($url, ['confirm' => '1'])->assertRedirect(route('login'));
        $this->actingAs($this->member())->post($url, ['confirm' => '1'])->assertForbidden();

        $this->assertSame(0, DB::table('memberships')->count());
    }

    public function test_the_setup_token_is_never_shown_to_the_admin(): void
    {
        $application = $this->application('approved');
        $this->actingAs($this->admin());
        $this->post(route('admin.membership-applications.activate', $application), ['confirm' => '1']);

        $page = $this->get(route('admin.membership-applications.show', $application))->assertOk();

        $hash = DB::table('account_setup_tokens')->value('token_hash');
        $page->assertDontSee($hash)->assertDontSee('/account/setup/');
    }

    // --- account setup email --------------------------------------------------

    public function test_activation_sends_exactly_one_setup_email_and_one_welcome_email(): void
    {
        Notification::fake();
        $application = $this->application('approved', ['email' => 'nimal@example.test', 'full_name' => 'Nimal Perera']);

        $this->actingAs($this->admin())
            ->post(route('admin.membership-applications.activate', $application), ['confirm' => '1'])
            ->assertSessionHas('status')
            ->assertSessionMissing('warning');

        $member = User::query()->where('email', 'nimal@example.test')->firstOrFail();
        Notification::assertCount(2);
        Notification::assertSentToTimes($member, AccountSetup::class, 1);
        Notification::assertSentToTimes($member, Welcome::class, 1);
    }

    public function test_the_email_carries_a_valid_link_built_from_the_one_and_only_issued_token(): void
    {
        Notification::fake();
        $application = $this->application('approved', ['email' => 'nimal@example.test']);
        $this->actingAs($this->admin())->post(route('admin.membership-applications.activate', $application), ['confirm' => '1']);

        $member = User::query()->where('email', 'nimal@example.test')->firstOrFail();
        $token = null;
        Notification::assertSentTo($member, AccountSetup::class, function (AccountSetup $notification) use (&$token): bool {
            $token = $this->tokenFromUrl($notification->setupUrl());

            return true;
        });

        $this->assertSame(1, DB::table('account_setup_tokens')->count(), 'Only one setup token is issued for an activation.');
        $this->assertSame(hash('sha256', $token), DB::table('account_setup_tokens')->value('token_hash'));
        $this->assertTrue(app(CompleteAccountSetup::class)->isUsable($token), 'Sending the email does not consume the token.');

        $this->get(route('account.setup', $token))->assertOk();
        $this->assertTrue(app(CompleteAccountSetup::class)->handle($token, 'a-Strong-pass-phrase-1'));
        $this->assertFalse(app(CompleteAccountSetup::class)->handle($token, 'another-Strong-pass-2'), 'Redeemable exactly once.');
    }

    public function test_the_email_is_sent_only_after_the_activation_has_committed(): void
    {
        $application = $this->application('approved');
        $admin = $this->admin();
        $baseline = DB::transactionLevel();
        $seen = [];
        $link = $this->captureSetupLinks(function () use (&$seen): void {
            $seen = [
                'memberships' => DB::table('memberships')->count(),
                'tokens' => DB::table('account_setup_tokens')->count(),
            ];
        });

        $this->actingAs($admin)->post(route('admin.membership-applications.activate', $application), ['confirm' => '1']);

        $this->assertCount(1, $link->urls);
        $this->assertSame([$baseline], $link->levels, 'No activation transaction is open while the email is sent.');
        $this->assertSame(['memberships' => 1, 'tokens' => 1], $seen);
    }

    public function test_a_failed_email_does_not_roll_back_the_activation_and_hides_the_detail(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message.' '.json_encode($event->context);
        });
        $link = $this->captureSetupLinks(function (string $url): void {
            throw new RuntimeException('SMTP 550 relay denied '.$url);
        });
        $application = $this->application('approved', ['email' => 'nimal@example.test']);

        $response = $this->actingAs($this->admin())
            ->post(route('admin.membership-applications.activate', $application), ['confirm' => '1']);

        $response->assertRedirect(route('admin.membership-applications.show', $application))
            ->assertSessionHas('warning', 'Membership activated, but the account setup email could not be sent.')
            ->assertSessionMissing('status')
            ->assertSessionHasNoErrors();

        $this->assertSame(1, DB::table('memberships')->count());
        $this->assertSame(1, DB::table('membership_terms')->count());
        $this->assertSame('pending_setup', User::query()->where('email', 'nimal@example.test')->value('status'));
        $this->assertSame(1, DB::table('account_setup_tokens')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'membership.activated')->count());

        $this->get(route('admin.membership-applications.show', $application))
            ->assertSee('Membership activated, but the account setup email could not be sent.')
            ->assertSee('Resend setup link')
            ->assertDontSee('SMTP')
            ->assertDontSee('relay denied');

        $token = $this->tokenFromUrl($link->urls[0]);
        $this->assertStringNotContainsString($token, implode("\n", $logged), 'The token is redacted from the log.');
    }

    public function test_the_plaintext_token_is_stored_nowhere_and_never_reaches_the_admin(): void
    {
        $link = $this->captureSetupLinks();
        $application = $this->application('approved');
        $this->actingAs($this->admin());

        $response = $this->post(route('admin.membership-applications.activate', $application), ['confirm' => '1']);

        $token = $this->tokenFromUrl($link->urls[0]);
        $this->assertTokenNotStored($token);
        $this->assertStringNotContainsString($token, json_encode(session()->all()), 'Not in the flash or session.');
        $this->assertStringNotContainsString($token, (string) $response->headers->get('Location'));
        $this->get(route('admin.membership-applications.show', $application))->assertDontSee($token);
    }
}
