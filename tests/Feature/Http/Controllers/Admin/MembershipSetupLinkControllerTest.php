<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Actions\Auth\CompleteAccountSetup;
use App\Actions\Membership\ActivateMembership;
use App\Models\Membership;
use App\Models\User;
use App\Notifications\Membership\AccountSetup;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\Concerns\InspectsSetupLinks;
use Tests\MysqlTestCase;

class MembershipSetupLinkControllerTest extends MysqlTestCase
{
    use CreatesReviewableApplications;
    use InspectsSetupLinks;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->admin();
    }

    /**
     * An activated application whose member has not yet created a password.
     *
     * @return array{Membership, string} the membership and the plaintext of its first token
     */
    private function activated(string $email = 'nimal@example.test'): array
    {
        $application = $this->application('approved', ['email' => $email]);
        $activated = app(ActivateMembership::class)->handle($application, $this->admin);

        return [$activated->membership, $activated->setupToken];
    }

    private function resendUrl(Membership $membership): string
    {
        return route('admin.membership-applications.setup-link', $membership->application);
    }

    public function test_resend_issues_a_new_token_supersedes_the_old_one_and_sends_exactly_one_email(): void
    {
        [$membership, $oldToken] = $this->activated();
        Notification::fake();

        $this->actingAs($this->admin)
            ->post($this->resendUrl($membership))
            ->assertRedirect(route('admin.membership-applications.show', $membership->application))
            ->assertSessionHas('status')
            ->assertSessionHasNoErrors();

        Notification::assertCount(1);
        Notification::assertSentToTimes($membership->user, AccountSetup::class, 1);

        $newToken = null;
        Notification::assertSentTo($membership->user, AccountSetup::class, function (AccountSetup $notification) use (&$newToken): bool {
            $newToken = $this->tokenFromUrl($notification->setupUrl());

            return true;
        });

        $setup = app(CompleteAccountSetup::class);
        $this->assertNotSame($oldToken, $newToken);
        $this->assertFalse($setup->isUsable($oldToken), 'The earlier link must stop working.');
        $this->assertTrue($setup->isUsable($newToken));

        $this->assertSame(2, DB::table('account_setup_tokens')->count());
        $this->assertSame(1, DB::table('account_setup_tokens')->whereNull('used_at')->whereNull('invalidated_at')->count());
        $this->assertSame('superseded', DB::table('account_setup_tokens')->where('token_hash', hash('sha256', $oldToken))->value('invalidated_reason'));
        $this->assertSame($this->admin->id, DB::table('account_setup_tokens')->where('token_hash', hash('sha256', $newToken))->value('issued_by_user_id'));
    }

    public function test_the_new_link_works_once_and_the_old_one_never_does(): void
    {
        [$membership, $oldToken] = $this->activated();
        $link = $this->captureSetupLinks();

        $this->actingAs($this->admin)->post($this->resendUrl($membership));

        $newToken = $this->tokenFromUrl($link->urls[0]);
        $this->get(route('account.setup', $oldToken))->assertNotFound();
        $this->get(route('account.setup', $newToken))->assertOk();

        $this->assertTrue(app(CompleteAccountSetup::class)->handle($newToken, 'a-Strong-pass-phrase-1'));
        $this->assertFalse(app(CompleteAccountSetup::class)->handle($newToken, 'another-Strong-pass-2'), 'A token is redeemable exactly once.');
        $this->assertSame('active', $membership->user->fresh()->status);
    }

    public function test_resend_writes_an_audit_event_and_never_stores_the_token(): void
    {
        [$membership, $oldToken] = $this->activated();
        $link = $this->captureSetupLinks();

        $this->actingAs($this->admin)->post($this->resendUrl($membership));

        $audit = DB::table('audit_logs')->where('event', 'membership.setup_link_resent')->get();
        $this->assertCount(1, $audit);
        $this->assertSame($this->admin->id, $audit[0]->user_id);
        $this->assertSame('membership', $audit[0]->subject_type);
        $this->assertSame($membership->id, $audit[0]->subject_id);
        $this->assertSame($membership->user_id, json_decode($audit[0]->new_values, true)['user_id']);

        $this->assertTokenNotStored($this->tokenFromUrl($link->urls[0]));
        $this->assertTokenNotStored($oldToken);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function usersNotPendingSetup(): array
    {
        return ['active' => ['active'], 'suspended' => ['suspended']];
    }

    #[DataProvider('usersNotPendingSetup')]
    public function test_a_member_who_is_not_pending_setup_cannot_be_sent_a_link(string $status): void
    {
        [$membership] = $this->activated();
        DB::table('users')->where('id', $membership->user_id)->update(['status' => $status]);
        $tokens = DB::table('account_setup_tokens')->count();
        $audit = DB::table('audit_logs')->count();
        Notification::fake();

        $this->actingAs($this->admin)
            ->post($this->resendUrl($membership))
            ->assertSessionHasErrors('setup_link');

        Notification::assertNothingSent();
        $this->assertSame($tokens, DB::table('account_setup_tokens')->count());
        $this->assertSame($audit, DB::table('audit_logs')->count());
        $this->assertSame($status, $membership->user->fresh()->status, 'The account is left untouched.');
    }

    public function test_an_application_that_was_never_activated_cannot_be_sent_a_link(): void
    {
        $application = $this->application('approved');
        Notification::fake();

        $this->actingAs($this->admin)
            ->post(route('admin.membership-applications.setup-link', $application))
            ->assertSessionHasErrors('setup_link');

        Notification::assertNothingSent();
    }

    public function test_the_resend_button_is_shown_only_while_the_member_is_pending_setup(): void
    {
        [$membership] = $this->activated();
        $show = route('admin.membership-applications.show', $membership->application);

        $this->actingAs($this->admin)->get($show)->assertSee('Resend setup link');

        DB::table('users')->where('id', $membership->user_id)->update(['status' => 'active']);
        $this->get($show)->assertDontSee('Resend setup link');
    }

    public function test_only_active_admins_can_resend(): void
    {
        [$membership] = $this->activated();
        Notification::fake();

        $this->post($this->resendUrl($membership))->assertRedirect(route('login'));
        $this->actingAs($this->member())->post($this->resendUrl($membership))->assertForbidden();

        Notification::assertNothingSent();
    }

    public function test_resend_is_throttled(): void
    {
        [$membership] = $this->activated();
        Notification::fake();
        $this->actingAs($this->admin);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->post($this->resendUrl($membership))->assertRedirect();
        }

        $this->post($this->resendUrl($membership))->assertStatus(429);
        Notification::assertCount(3);
        $this->assertSame(4, DB::table('account_setup_tokens')->count(), 'The throttled request issues nothing.');
    }

    public function test_the_throttle_is_per_member_so_other_members_are_not_blocked(): void
    {
        [$first] = $this->activated('first@example.test');
        [$second] = $this->activated('second@example.test');
        Notification::fake();
        $this->actingAs($this->admin);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->post($this->resendUrl($first));
        }

        $this->post($this->resendUrl($first))->assertStatus(429);
        $this->post($this->resendUrl($second))->assertSessionHas('status');
    }

    /**
     * A single-connection test cannot run two requests in parallel, so the losing side of
     * the race is reproduced at the moment that matters: after request B has superseded
     * the live token but before it inserts its own, request A's committed token is live
     * again (B never saw it). B's insert then hits the real MySQL `live_key` UNIQUE index.
     */
    public function test_two_competing_resends_cannot_both_rotate_the_token(): void
    {
        [$membership, $originalToken] = $this->activated();
        Notification::fake();
        $this->actingAs($this->admin);

        // Request A wins the live-token slot.
        $this->post($this->resendUrl($membership))->assertSessionHas('status');

        $winningToken = null;
        Notification::assertSentTo($membership->user, AccountSetup::class, function (AccountSetup $notification) use (&$winningToken): bool {
            $winningToken = $this->tokenFromUrl($notification->setupUrl());

            return true;
        });

        // Request B supersedes what it can see, then collides with A's live token on insert.
        $collide = true;
        DB::beforeExecuting(function (string $query) use (&$collide, $winningToken): void {
            if ($collide && str_starts_with($query, 'insert into `account_setup_tokens`')) {
                $collide = false;
                DB::table('account_setup_tokens')
                    ->where('token_hash', hash('sha256', $winningToken))
                    ->update(['invalidated_at' => null, 'invalidated_reason' => null]);
            }
        });

        $this->post($this->resendUrl($membership))
            ->assertRedirect(route('admin.membership-applications.show', $membership->application))
            ->assertSessionHasErrors(['setup_link' => 'A setup link was just issued for this member. Wait a moment and try again.'])
            ->assertSessionMissing('status');

        $this->assertFalse($collide, 'The competing insert was reached.');
        Notification::assertCount(1);

        // Exactly one valid latest token remains: A's. B's attempt left nothing behind.
        $setup = app(CompleteAccountSetup::class);
        $live = DB::table('account_setup_tokens')->whereNull('used_at')->whereNull('invalidated_at')->pluck('token_hash')->all();
        $this->assertSame([hash('sha256', $winningToken)], $live);
        $this->assertSame(2, DB::table('account_setup_tokens')->count());
        $this->assertTrue($setup->isUsable($winningToken));
        $this->assertFalse($setup->isUsable($originalToken));
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'membership.setup_link_resent')->count(), 'Only the winning request is audited.');
        $this->assertSame('pending_setup', $membership->user->fresh()->status);
        $this->assertTokenNotStored($winningToken);
        $this->assertTokenNotStored($originalToken);

        // The rejected attempt still counts towards the throttle: A and B used two of three.
        $this->post($this->resendUrl($membership))->assertSessionHas('status');
        $this->post($this->resendUrl($membership))->assertStatus(429);
    }

    public function test_a_failed_send_keeps_the_new_token_reports_a_clear_message_and_hides_the_detail(): void
    {
        [$membership] = $this->activated();
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message.' '.json_encode($event->context);
        });
        $link = $this->captureSetupLinks(function (string $url): void {
            throw new RuntimeException('SMTP 550 mailbox unavailable for '.$url);
        });

        $response = $this->actingAs($this->admin)->post($this->resendUrl($membership));

        $response->assertSessionHas('warning', 'A new setup link was issued, but the account setup email could not be sent.');
        $response->assertSessionMissing('status');
        $this->get(route('admin.membership-applications.show', $membership->application))
            ->assertSee('could not be sent')
            ->assertDontSee('SMTP')
            ->assertDontSee('mailbox unavailable');

        $token = $this->tokenFromUrl($link->urls[0]);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'membership.setup_link_resent')->count());
        $this->assertStringNotContainsString($token, implode("\n", $logged), 'The token is redacted from the log.');
        $this->assertStringContainsString('user_id', implode("\n", $logged));
        $this->assertTokenNotStored($token);
    }
}
