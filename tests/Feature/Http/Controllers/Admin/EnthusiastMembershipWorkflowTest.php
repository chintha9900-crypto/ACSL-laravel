<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Actions\Auth\CompleteAccountSetup;
use App\Actions\Payments\ConfirmPayment;
use App\Actions\Payments\SubmitPaymentEvidence;
use App\Models\MembershipApplication;
use App\Models\MembershipCategory;
use App\Models\MembershipTerm;
use App\Models\User;
use App\Notifications\Membership\AccountSetup;
use App\Notifications\Membership\ApprovedIntroductory;
use App\Notifications\Membership\Welcome;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesRenewals;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\Concerns\CreatesUploads;
use Tests\Concerns\InspectsSetupLinks;
use Tests\MysqlTestCase;

/**
 * End-to-end regression coverage for the Aviation Enthusiast category (Step
 * 5): the real public submission → admin review → admin activation chain,
 * through to account setup, the digital card, verification, and renewal —
 * using the same routes/Actions as every other category, not a parallel
 * mechanism.
 */
class EnthusiastMembershipWorkflowTest extends MysqlTestCase
{
    use CreatesRenewals;
    use CreatesReviewableApplications;
    use CreatesUploads;
    use InspectsSetupLinks;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
        Storage::fake('public');
    }

    private function enthusiastCategory(): MembershipCategory
    {
        return MembershipCategory::query()->where('code', MembershipCategory::CODE_ENTHUSIAST)->first()
            ?? MembershipCategory::factory()->enthusiast()->create();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function enthusiastPayload(array $overrides = []): array
    {
        return [
            'category' => MembershipCategory::CODE_ENTHUSIAST,
            'full_name' => 'Kasun Silva',
            'email' => 'kasun.silva@example.test',
            'mobile' => '+94 77 987 6543',
            'address' => '45 Galle Road, Colombo',
            'aviation_role' => 'Aviation Enthusiast',
            'aviation_organisation' => 'N/A',
            'declaration' => '1',
            ...$overrides,
        ];
    }

    /**
     * Items 1–8: submit (with no proof at all) → admin approves → admin
     * activates — entirely through the public form and the existing admin
     * review/activation controllers, no shortcuts.
     */
    public function test_full_lifecycle_from_application_to_activation(): void
    {
        Notification::fake();
        $this->enthusiastCategory();

        // 1–2. Submit with only the permitted basic fields — no
        // `proof_documents` key at all, and nothing is stored for it.
        $this->post(route('membership.apply.store'), $this->enthusiastPayload())
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertSame(0, DB::table('documents')->count());

        // 3. Stored with category E.
        $application = MembershipApplication::query()->firstOrFail();
        $this->assertSame(MembershipCategory::CODE_ENTHUSIAST, $application->category->code);
        $this->assertSame('submitted', $application->status);

        // 4. Admin reviews and approves it via the existing workflow — M4 fires.
        $admin = $this->admin();
        $this->actingAs($admin)
            ->post(route('admin.membership-applications.review', $application), [
                'decision' => 'approved',
                'proof_reviewed' => '1',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('approved', $application->fresh()->status);
        Notification::assertSentOnDemand(ApprovedIntroductory::class);

        // 4 (continued). Admin activates.
        $this->actingAs($admin)
            ->post(route('admin.membership-applications.activate', $application), ['confirm' => '1'])
            ->assertSessionHas('status');

        // 5. Exactly one User and one Membership.
        $this->assertSame(1, DB::table('users')->where('role', 'member')->count());
        $this->assertSame(1, DB::table('memberships')->count());
        $member = User::query()->where('email', 'kasun.silva@example.test')->firstOrFail();

        // 6. Membership number EYYRRSSSS.
        $membership = DB::table('memberships')->first();
        $this->assertMatchesRegularExpression('/^E\d{2}\d{2}\d{4}$/', $membership->membership_number);

        // 7. The initial term is exactly 3 calendar months.
        $term = DB::table('membership_terms')->where('membership_id', $membership->id)->first();
        $this->assertSame(3, $term->duration_months);

        // 8. No £0/any payment record for the introductory term.
        $this->assertSame(0, DB::table('payments')->count());
        $this->assertNull($term->fee_amount);

        // 9–10. Account setup + welcome notifications, same mechanism as
        // every other category.
        Notification::assertSentToTimes($member, AccountSetup::class, 1);
        Notification::assertSentToTimes($member, Welcome::class, 1);

        // Replay guard: a duplicate activation submission creates nothing new.
        $this->actingAs($admin)
            ->post(route('admin.membership-applications.activate', $application), ['confirm' => '1'])
            ->assertSessionHasErrors(['activation' => 'This application has already been activated.']);
        $this->assertSame(1, DB::table('memberships')->count());
        $this->assertSame(1, DB::table('users')->where('role', 'member')->count());
    }

    /**
     * Item 9 (continued): the existing account-setup mechanism — the
     * captured setup link works and can only be redeemed once.
     */
    public function test_account_setup_completes_and_the_member_can_sign_in(): void
    {
        $this->enthusiastCategory();
        $admin = $this->admin();
        $link = $this->captureSetupLinks();

        $this->post(route('membership.apply.store'), $this->enthusiastPayload())->assertRedirect();
        $application = MembershipApplication::query()->firstOrFail();
        $this->actingAs($admin)->post(route('admin.membership-applications.review', $application), ['decision' => 'approved', 'proof_reviewed' => '1']);
        $this->actingAs($admin)->post(route('admin.membership-applications.activate', $application), ['confirm' => '1']);

        $token = $this->tokenFromUrl($link->urls[0]);
        $this->assertTrue(app(CompleteAccountSetup::class)->handle($token, 'a-Strong-pass-phrase-1'));

        $member = User::query()->where('email', 'kasun.silva@example.test')->firstOrFail();
        $this->assertSame('active', $member->fresh()->status);

        // Still "acting as" the admin from the review/activate steps above —
        // sign out first, or the `guest` middleware silently redirects the
        // login attempt away without processing it.
        auth()->logout();
        $this->post('/login', ['email' => 'kasun.silva@example.test', 'password' => 'a-Strong-pass-phrase-1'])
            ->assertRedirect(route('member.dashboard'));
        $this->assertAuthenticatedAs($member);
    }

    /**
     * Items 11–12: the digital membership card and its QR/verification
     * token — the exact same controller/view every category uses.
     */
    public function test_digital_card_and_verification_work(): void
    {
        $membership = $this->activatedMembership(['full_name' => 'Kasun Silva'], 'E');

        $this->actingAs($membership->user)
            ->get(route('member.membership.card'))
            ->assertOk()
            ->assertSee('Kasun Silva')
            ->assertSee($membership->membership_number);

        $this->get(route('verification.show', ['token' => $membership->verification_token]))
            ->assertOk()
            ->assertSee('You are a member of Aviation Club International')
            ->assertSee('Kasun Silva')
            ->assertSee($membership->membership_number);
    }

    /**
     * Items 13–14: renewal after the introductory term uses the real,
     * seeded Enthusiast plan (LKR 2,000 / 12 months — docs/database/04 §4),
     * and the membership number never changes.
     */
    public function test_renewal_uses_the_enthusiast_plan_and_keeps_the_membership_number(): void
    {
        $membership = $this->renewableMembership(['full_name' => 'Kasun Silva'], 'E');
        $originalNumber = $membership->membership_number;
        $membership->terms->first()->forceFill(['starts_on' => now()->subMonths(4), 'expires_on' => now()->subDay()])->save();

        $this->actingAs($membership->user->fresh())
            ->post(route('member.membership.renewal.start'))
            ->assertRedirect(route('member.membership.show'));

        $term = DB::table('membership_terms')->where('status', 'pending_payment')->first();
        $this->assertSame('2000.00', $term->fee_amount);
        $this->assertSame(12, $term->duration_months);

        $payment = MembershipTerm::find($term->id)->payment;
        app(SubmitPaymentEvidence::class)->handle($payment, $membership->user, 'REF-ENTHUSIAST-1', $this->pdfUpload());
        app(ConfirmPayment::class)->handle($payment->fresh(), $this->admin());

        $this->assertTrue($membership->fresh()->hasCurrentTerm());
        $this->assertSame($originalNumber, $membership->fresh()->membership_number, 'The membership number never changes across a renewal.');
        $this->assertSame(2, DB::table('membership_terms')->where('membership_id', $membership->id)->count());
    }
}
