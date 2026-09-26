<?php

namespace Tests\Feature\Http\Controllers\Member;

use App\Actions\Membership\ActivateMembership;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\Concerns\CreatesUploads;
use Tests\MysqlTestCase;

class ProfileControllerTest extends MysqlTestCase
{
    use CreatesReviewableApplications;
    use CreatesUploads;

    private function pngUpload(string $name = 'avatar.png'): UploadedFile
    {
        return $this->realUpload($name, $this->pngContent(), 'image/png');
    }

    /**
     * A real PNG (valid header, so content-sniffing still identifies it as one)
     * padded to a given size — avoids depending on the GD extension for a
     * generated image, which this environment does not have installed.
     */
    private function pngUploadOfSize(int $kilobytes): UploadedFile
    {
        $content = $this->pngContent();
        $content .= str_repeat("\0", max(0, $kilobytes * 1024 - strlen($content)));

        return $this->realUpload('avatar.png', $content, 'image/png');
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Nimal Perera',
            'phone' => '+94771234567',
            'country' => 'Sri Lanka',
            'aviation_occupation' => 'Pilot',
            'job_title' => 'Captain',
            'company' => 'SriLankan Airlines',
            'linkedin_url' => 'https://www.linkedin.com/in/nimal-perera',
            'bio' => 'Twenty years flying commercial routes.',
        ], $overrides);
    }

    public function test_an_authenticated_member_can_view_their_own_profile(): void
    {
        $user = $this->member();
        $user->forceFill(['phone' => '+94711111111'])->save();

        $this->actingAs($user)
            ->get(route('member.profile.show'))
            ->assertOk()
            ->assertSee($user->name)
            ->assertSee($user->email)
            ->assertSee('+94711111111')
            ->assertSee('cannot be changed here');
    }

    public function test_an_unauthenticated_visitor_is_redirected_to_login(): void
    {
        $this->get(route('member.profile.show'))->assertRedirect(route('login'));
        $this->patch(route('member.profile.update'), $this->validPayload())->assertRedirect(route('login'));
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

        $this->actingAs($user)->get(route('member.profile.show'))->assertRedirect(route('login'));
        $this->actingAs($user)->patch(route('member.profile.update'), $this->validPayload())->assertRedirect(route('login'));
    }

    public function test_a_member_can_update_the_permitted_fields(): void
    {
        $user = $this->member();

        $this->actingAs($user)
            ->patch(route('member.profile.update'), $this->validPayload())
            ->assertRedirect(route('member.profile.show'))
            ->assertSessionHas('status');

        $user->refresh();
        $this->assertSame('Nimal Perera', $user->name);
        $this->assertSame('+94771234567', $user->phone);
        $this->assertSame('Sri Lanka', $user->country);
        $this->assertSame('Pilot', $user->aviation_occupation);
        $this->assertSame('Captain', $user->job_title);
        $this->assertSame('SriLankan Airlines', $user->company);
        $this->assertSame('https://www.linkedin.com/in/nimal-perera', $user->linkedin_url);
        $this->assertSame('Twenty years flying commercial routes.', $user->bio);
    }

    public function test_clearing_an_optional_field_stores_null_instead_of_an_empty_string(): void
    {
        $user = $this->member();
        $user->forceFill(['bio' => 'Old bio.'])->save();

        $this->actingAs($user)
            ->patch(route('member.profile.update'), $this->validPayload(['bio' => '']))
            ->assertSessionHasNoErrors();

        $this->assertNull($user->refresh()->bio);
    }

    public function test_protected_fields_cannot_be_changed_through_request_input(): void
    {
        $user = $this->member();
        $originalEmail = $user->email;
        $originalPassword = $user->password;
        $originalRememberToken = $user->remember_token;
        $originalVerifiedAt = $user->email_verified_at;

        $this->actingAs($user)
            ->patch(route('member.profile.update'), $this->validPayload([
                'email' => 'hijacked@example.test',
                'role' => 'admin',
                'status' => 'suspended',
                'password' => 'a-New-Strong-Password-1',
                'email_verified_at' => null,
                'remember_token' => 'attacker-controlled-token',
            ]))
            ->assertRedirect(route('member.profile.show'))
            ->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertSame($originalEmail, $user->email);
        $this->assertSame('member', $user->role);
        $this->assertSame('active', $user->status);
        $this->assertSame($originalPassword, $user->password);
        $this->assertSame($originalRememberToken, $user->remember_token);
        $this->assertEquals($originalVerifiedAt, $user->email_verified_at);
        $this->assertSame('Nimal Perera', $user->name, 'The permitted field in the same request is still applied.');
    }

    public function test_a_member_cannot_view_or_update_another_members_profile(): void
    {
        $me = $this->member();
        $someoneElse = $this->member();
        $before = DB::table('users')->where('id', $someoneElse->id)->first();

        // Nothing in the URL identifies whose profile this is, but try anyway.
        $this->actingAs($me)
            ->get(route('member.profile.show', ['user' => $someoneElse->id]))
            ->assertOk()
            ->assertSee($me->name)
            ->assertDontSee($someoneElse->name)
            ->assertDontSee($someoneElse->email);

        // Try to smuggle the other user's id into the update payload.
        $this->actingAs($me)
            ->patch(route('member.profile.update'), $this->validPayload([
                'id' => $someoneElse->id,
                'user_id' => $someoneElse->id,
                'name' => 'Attacker Controlled Name',
            ]))
            ->assertRedirect(route('member.profile.show'));

        $this->assertSame('Attacker Controlled Name', $me->fresh()->name, 'Only the acting member changes.');
        $this->assertEquals($before, DB::table('users')->where('id', $someoneElse->id)->first(), 'The other member is untouched.');
    }

    public function test_an_invalid_phone_number_is_rejected(): void
    {
        $user = $this->member();

        $this->actingAs($user)
            ->patch(route('member.profile.update'), $this->validPayload(['phone' => 'not-a-phone-number']))
            ->assertSessionHasErrors('phone');

        $this->assertNull($user->fresh()->phone);
    }

    public function test_an_invalid_linkedin_url_is_rejected(): void
    {
        $user = $this->member();

        $this->actingAs($user)
            ->patch(route('member.profile.update'), $this->validPayload(['linkedin_url' => 'not-a-url']))
            ->assertSessionHasErrors('linkedin_url');

        $this->assertNull($user->fresh()->linkedin_url);
    }

    public function test_avatar_content_that_does_not_match_its_extension_is_rejected(): void
    {
        Storage::fake('public');
        $user = $this->member();
        $disguised = $this->realUpload('avatar.png', '<?php system($_GET["c"]); ?>', 'image/png');

        $this->actingAs($user)
            ->patch(route('member.profile.update'), $this->validPayload(['avatar' => $disguised]))
            ->assertSessionHasErrors('avatar');

        $this->assertNull($user->fresh()->avatar_path);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_an_oversized_avatar_is_rejected(): void
    {
        Storage::fake('public');
        $user = $this->member();
        $tooLarge = $this->pngUploadOfSize(config('uploads.avatar.max_kb') + 1);

        $this->actingAs($user)
            ->patch(route('member.profile.update'), $this->validPayload(['avatar' => $tooLarge]))
            ->assertSessionHasErrors('avatar');

        $this->assertNull($user->fresh()->avatar_path);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_a_disallowed_file_type_is_rejected_as_an_avatar(): void
    {
        Storage::fake('public');
        $user = $this->member();

        $this->actingAs($user)
            ->patch(route('member.profile.update'), $this->validPayload([
                'avatar' => $this->realUpload('avatar.pdf', $this->pdfContent(), 'application/pdf'),
            ]))
            ->assertSessionHasErrors('avatar');

        $this->assertNull($user->fresh()->avatar_path);
    }

    public function test_a_valid_avatar_is_stored_on_the_public_disk_under_a_server_generated_path(): void
    {
        Storage::fake('public');
        $user = $this->member();

        $this->actingAs($user)
            ->patch(route('member.profile.update'), $this->validPayload(['avatar' => $this->pngUpload('my-face.png')]))
            ->assertSessionHasNoErrors();

        $path = $user->fresh()->avatar_path;
        $this->assertNotNull($path);
        $this->assertStringStartsWith('avatars/', $path);
        $this->assertStringNotContainsString('my-face', $path, 'The client filename must not appear in the stored path.');
        $this->assertStringNotContainsString('..', $path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_replacing_the_avatar_removes_the_previous_file(): void
    {
        Storage::fake('public');
        $user = $this->member();

        $this->actingAs($user)->patch(route('member.profile.update'), $this->validPayload(['avatar' => $this->pngUpload()]));
        $firstPath = $user->fresh()->avatar_path;
        Storage::disk('public')->assertExists($firstPath);

        $this->actingAs($user)->patch(route('member.profile.update'), $this->validPayload(['avatar' => $this->pngUpload()]));
        $secondPath = $user->fresh()->avatar_path;

        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($secondPath);
    }

    public function test_a_failed_update_leaves_a_previously_stored_avatar_in_place(): void
    {
        Storage::fake('public');
        $user = $this->member();
        $this->actingAs($user)->patch(route('member.profile.update'), $this->validPayload(['avatar' => $this->pngUpload()]));
        $originalPath = $user->fresh()->avatar_path;

        $this->actingAs($user)
            ->patch(route('member.profile.update'), $this->validPayload([
                'phone' => 'not-a-phone-number',
                'avatar' => $this->pngUpload(),
            ]))
            ->assertSessionHasErrors('phone');

        $this->assertSame($originalPath, $user->fresh()->avatar_path);
        Storage::disk('public')->assertExists($originalPath);
    }

    public function test_membership_information_is_shown_read_only_when_the_member_has_a_membership(): void
    {
        $application = $this->application('approved', ['full_name' => 'Nimal Perera']);
        $activated = app(ActivateMembership::class)->handle($application, $this->admin());
        $member = $activated->membership->user;
        $member->forceFill(['status' => 'active'])->save();

        $this->actingAs($member)
            ->get(route('member.profile.show'))
            ->assertOk()
            ->assertSee($activated->membership->membership_number)
            ->assertSee('read-only');
    }

    public function test_no_database_migration_is_required_for_the_profile_fields(): void
    {
        $this->assertTrue(Schema::hasColumns('users', [
            'name', 'phone', 'country', 'aviation_occupation', 'job_title', 'company', 'linkedin_url', 'bio', 'avatar_path',
        ]), 'Every profile field must already exist on users without a new migration.');
    }
}
