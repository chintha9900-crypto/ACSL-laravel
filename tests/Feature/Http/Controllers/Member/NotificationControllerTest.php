<?php

namespace Tests\Feature\Http\Controllers\Member;

use App\Actions\Membership\ActivateMembership;
use App\Models\User;
use App\Notifications\Membership\AccountSetup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\MysqlTestCase;

class NotificationControllerTest extends MysqlTestCase
{
    use CreatesReviewableApplications;

    /**
     * Insert a database notification row directly (no real mail dispatch, no
     * queue) so pagination/ordering/unread-count scenarios can be built quickly.
     * `notifiable_type` is written as the enforced 'user' morph alias, exactly as
     * the real `database` channel would write it.
     */
    private function createNotification(User $user, array $overrides = []): string
    {
        $id = (string) Str::uuid();

        DB::table('notifications')->insert(array_merge([
            'id' => $id,
            'type' => AccountSetup::class,
            'notifiable_type' => 'user',
            'notifiable_id' => $user->id,
            'data' => json_encode([
                'title' => 'Set up your account',
                'message' => 'Your Aviation Club International membership is active.',
                'action_url' => null,
                'related_public_id' => null,
            ]),
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return $id;
    }

    public function test_a_member_can_view_their_own_notifications(): void
    {
        $user = $this->member();
        $this->createNotification($user);

        $this->actingAs($user)
            ->get(route('member.notifications.show'))
            ->assertOk()
            ->assertSee('Set up your account')
            ->assertSee('Your Aviation Club International membership is active.');
    }

    public function test_the_empty_state_is_shown_when_there_are_no_notifications(): void
    {
        $this->actingAs($this->member())
            ->get(route('member.notifications.show'))
            ->assertOk()
            ->assertSee('No notifications.');
    }

    public function test_an_unauthenticated_visitor_is_redirected_to_login(): void
    {
        $this->get(route('member.notifications.show'))->assertRedirect(route('login'));
        $this->post(route('member.notifications.mark-all-read'))->assertRedirect(route('login'));
        $this->post(route('member.notifications.mark-read', 'anything'))->assertRedirect(route('login'));
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

        $this->actingAs($user)->get(route('member.notifications.show'))->assertRedirect(route('login'));
        $this->actingAs($user)->post(route('member.notifications.mark-all-read'))->assertRedirect(route('login'));
    }

    public function test_notifications_are_shown_newest_first(): void
    {
        $user = $this->member();
        $oldest = $this->createNotification($user, ['data' => json_encode(['title' => 'Oldest', 'message' => 'm']), 'created_at' => now()->subDays(2)]);
        $middle = $this->createNotification($user, ['data' => json_encode(['title' => 'Middle', 'message' => 'm']), 'created_at' => now()->subDay()]);
        $newest = $this->createNotification($user, ['data' => json_encode(['title' => 'Newest', 'message' => 'm']), 'created_at' => now()]);

        $response = $this->actingAs($user)->get(route('member.notifications.show'))->assertOk();

        $ids = $response->viewData('notifications')->pluck('id')->all();
        $this->assertSame([$newest, $middle, $oldest], $ids);
    }

    public function test_notifications_are_paginated_at_fifteen_per_page(): void
    {
        $user = $this->member();
        collect(range(1, 20))->each(fn () => $this->createNotification($user));

        $firstPage = $this->actingAs($user)->get(route('member.notifications.show'))->assertOk();
        $paginator = $firstPage->viewData('notifications');
        $this->assertCount(15, $paginator->items());
        $this->assertTrue($paginator->hasMorePages());

        $secondPage = $this->actingAs($user)->get(route('member.notifications.show', ['page' => 2]))->assertOk();
        $this->assertCount(5, $secondPage->viewData('notifications')->items());
    }

    public function test_the_unread_count_covers_every_notification_not_only_the_current_page(): void
    {
        $user = $this->member();
        collect(range(1, 20))->each(fn () => $this->createNotification($user));
        // A read one must not be counted.
        $this->createNotification($user, ['read_at' => now()]);

        $response = $this->actingAs($user)->get(route('member.notifications.show'))->assertOk();

        $this->assertSame(20, $response->viewData('unreadCount'));
        $this->assertSame(15, $response->viewData('notifications')->count(), 'The page itself only holds 15.');
        $response->assertSee('20 unread');
    }

    public function test_marking_a_single_notification_read(): void
    {
        $user = $this->member();
        $id = $this->createNotification($user);

        $this->actingAs($user)
            ->post(route('member.notifications.mark-read', $id))
            ->assertRedirect(route('member.notifications.show'));

        $this->assertNotNull(DB::table('notifications')->where('id', $id)->value('read_at'));
    }

    public function test_marking_a_single_notification_read_does_not_affect_others(): void
    {
        $user = $this->member();
        $target = $this->createNotification($user);
        $other = $this->createNotification($user);

        $this->actingAs($user)->post(route('member.notifications.mark-read', $target));

        $this->assertNotNull(DB::table('notifications')->where('id', $target)->value('read_at'));
        $this->assertNull(DB::table('notifications')->where('id', $other)->value('read_at'));
    }

    public function test_marking_all_notifications_read(): void
    {
        $user = $this->member();
        collect(range(1, 5))->each(fn () => $this->createNotification($user));

        $this->actingAs($user)
            ->post(route('member.notifications.mark-all-read'))
            ->assertRedirect(route('member.notifications.show'))
            ->assertSessionHas('status', 'All notifications marked as read.');

        $this->assertSame(0, DB::table('notifications')->where('notifiable_id', $user->id)->whereNull('read_at')->count());
    }

    public function test_a_member_cannot_mark_another_members_notification_as_read(): void
    {
        $me = $this->member();
        $someoneElse = $this->member();
        $theirNotification = $this->createNotification($someoneElse);

        $this->actingAs($me)
            ->post(route('member.notifications.mark-read', $theirNotification))
            ->assertRedirect(route('member.notifications.show'));

        $this->assertNull(
            DB::table('notifications')->where('id', $theirNotification)->value('read_at'),
            'A notification id belonging to another member must not be reachable at all.'
        );
    }

    public function test_a_member_cannot_use_mark_all_read_to_touch_another_members_notifications(): void
    {
        $me = $this->member();
        $someoneElse = $this->member();
        $theirNotification = $this->createNotification($someoneElse);
        $this->createNotification($me);

        $this->actingAs($me)->post(route('member.notifications.mark-all-read'));

        $this->assertNull(DB::table('notifications')->where('id', $theirNotification)->value('read_at'));
    }

    public function test_a_member_cannot_view_another_members_notifications_via_a_spoofed_identifier(): void
    {
        $me = $this->member();
        $someoneElse = $this->member();
        $this->createNotification($me, ['data' => json_encode(['title' => 'Mine', 'message' => 'm'])]);
        $this->createNotification($someoneElse, ['data' => json_encode(['title' => 'Theirs', 'message' => 'm'])]);

        // The route accepts no user/member id at all; try to smuggle one anyway.
        $response = $this->actingAs($me)
            ->get(route('member.notifications.show', ['user' => $someoneElse->id, 'user_id' => $someoneElse->id, 'notifiable_id' => $someoneElse->id]))
            ->assertOk();

        $response->assertSee('Mine')->assertDontSee('Theirs');
    }

    public function test_activating_a_membership_creates_a_database_notification_with_no_secret(): void
    {
        $application = $this->application('approved', ['email' => 'nimal@example.test']);

        // The full HTTP flow: ActivateMembership commits, then the controller
        // delivers the AccountSetup notification (mail + database) afterwards.
        $this->actingAs($this->admin())
            ->post(route('admin.membership-applications.activate', $application), ['confirm' => '1'])
            ->assertSessionHas('status');

        $member = User::query()->where('email', 'nimal@example.test')->firstOrFail();
        // Activation now also sends the M8 Welcome notification (mail + database)
        // alongside M9/AccountSetup, so this looks up AccountSetup's row by type.
        $row = DB::table('notifications')
            ->where('notifiable_id', $member->id)
            ->where('notifiable_type', 'user')
            ->where('type', AccountSetup::class)
            ->first();

        $this->assertNotNull($row, 'Activation must create an in-app notification for the new member.');
        $this->assertSame(AccountSetup::class, $row->type);

        $data = json_decode($row->data, true);
        $this->assertSame('Set up your account', $data['title']);
        $this->assertArrayHasKey('message', $data);
        $this->assertNull($data['action_url'], 'The one-time setup token must never reach the database channel.');

        $setupTokenHash = DB::table('account_setup_tokens')->where('user_id', $member->id)->value('token_hash');
        $this->assertNotNull($setupTokenHash);
        $this->assertStringNotContainsString($setupTokenHash, $row->data);
    }

    public function test_the_notifications_link_appears_in_the_member_navigation(): void
    {
        $this->actingAs($this->member())
            ->get(route('member.profile.show'))
            ->assertOk()
            ->assertSee(route('member.notifications.show'), false);
    }
}
