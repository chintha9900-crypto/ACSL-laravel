<?php

namespace Tests\Feature\Http\Controllers\Admin;

use App\Actions\Auth\CompleteAccountSetup;
use App\Actions\Payments\ConfirmPayment;
use App\Actions\Payments\SubmitPaymentEvidence;
use App\Models\Document;
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
 * End-to-end regression coverage for the Corporate category (Step 5): the
 * real public submission → admin review → admin activation chain, through
 * to account setup, the digital card, verification, and renewal — using the
 * same routes/Actions as every other category. One company = one
 * membership, one representative/company-admin account, one card; never a
 * per-seat system.
 */
class CorporateMembershipWorkflowTest extends MysqlTestCase
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

    private function corporateCategory(): MembershipCategory
    {
        return MembershipCategory::query()->where('code', MembershipCategory::CODE_CORPORATE)->first()
            ?? MembershipCategory::factory()->corporate()->create();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function corporatePayload(array $overrides = []): array
    {
        return [
            'category' => MembershipCategory::CODE_CORPORATE,
            'full_name' => 'Priya Fernando',
            'email' => 'priya.fernando@example-airline.test',
            'mobile' => '+94 77 555 1234',
            'address' => '1 Airport Way, Katunayake',
            'aviation_role' => 'Airline',
            'aviation_organisation' => 'Head of HR',
            'company_name' => 'Example Airline (Pvt) Ltd',
            'company_email' => 'info@example-airline.test',
            'company_phone' => '+94 11 234 5678',
            'company_website' => 'https://example-airline.test',
            'proof_documents' => [$this->pdfUpload('company-request-letter.pdf')],
            'declaration' => '1',
            ...$overrides,
        ];
    }

    /**
     * Items 1–6, 9–11: submit (company + representative details + company
     * letter) → admin approves → admin activates.
     */
    public function test_full_lifecycle_from_application_to_activation(): void
    {
        Notification::fake();
        $this->corporateCategory();

        // 1, 4. Submit with company details, representative details and the
        // company request letter — the existing private proof_documents
        // mechanism, no new upload system.
        $this->post(route('membership.apply.store'), $this->corporatePayload())
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('documents')->count());

        // 5. Stored with category C and all company fields.
        $application = MembershipApplication::query()->firstOrFail();
        $this->assertSame(MembershipCategory::CODE_CORPORATE, $application->category->code);
        $this->assertSame('Example Airline (Pvt) Ltd', $application->company_name);
        $this->assertSame('info@example-airline.test', $application->company_email);
        $this->assertSame('+94 11 234 5678', $application->company_phone);
        $this->assertSame('https://example-airline.test', $application->company_website);
        $this->assertSame('Priya Fernando', $application->full_name);

        // 6. Admin reviews and approves it via the existing workflow.
        $admin = $this->admin();
        $this->actingAs($admin)
            ->post(route('admin.membership-applications.review', $application), [
                'decision' => 'approved',
                'proof_reviewed' => '1',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('approved', $application->fresh()->status);
        Notification::assertSentOnDemand(ApprovedIntroductory::class);

        // 7 (activation). Admin activates.
        $this->actingAs($admin)
            ->post(route('admin.membership-applications.activate', $application), ['confirm' => '1'])
            ->assertSessionHas('status');

        // 7. Exactly one User, one Membership (one card follows from that —
        // asserted separately below).
        $this->assertSame(1, DB::table('users')->where('role', 'member')->count());
        $this->assertSame(1, DB::table('memberships')->count());

        // 8. The User represents the company REPRESENTATIVE, not the
        // company itself — same "one account per membership" mechanism as
        // every other category.
        $member = User::query()->where('email', 'priya.fernando@example-airline.test')->firstOrFail();
        $this->assertSame('Priya Fernando', $member->name);

        // 9. Membership number CYYRRSSSS.
        $membership = DB::table('memberships')->first();
        $this->assertMatchesRegularExpression('/^C\d{2}\d{2}\d{4}$/', $membership->membership_number);

        // 10. The initial term is exactly 3 calendar months.
        $term = DB::table('membership_terms')->where('membership_id', $membership->id)->first();
        $this->assertSame(3, $term->duration_months);

        // 11. No payment record for the introductory term.
        $this->assertSame(0, DB::table('payments')->count());
        $this->assertNull($term->fee_amount);

        // 12. Account setup + welcome notifications.
        Notification::assertSentToTimes($member, AccountSetup::class, 1);
        Notification::assertSentToTimes($member, Welcome::class, 1);

        // Replay guard: no duplicate company/user/membership on a repeat submission.
        $this->actingAs($admin)
            ->post(route('admin.membership-applications.activate', $application), ['confirm' => '1'])
            ->assertSessionHasErrors(['activation' => 'This application has already been activated.']);
        $this->assertSame(1, DB::table('memberships')->count());
        $this->assertSame(1, DB::table('users')->where('role', 'member')->count());
    }

    public function test_company_website_is_optional_and_the_request_letter_uses_the_private_disk(): void
    {
        $this->corporateCategory();

        $payload = $this->corporatePayload();
        unset($payload['company_website']);

        $this->post(route('membership.apply.store'), $payload)->assertRedirect()->assertSessionHasNoErrors();

        $application = MembershipApplication::query()->firstOrFail();
        $this->assertNull($application->company_website);

        $document = Document::query()->where('membership_application_id', $application->id)->firstOrFail();
        $this->assertSame(Document::DISK_PRIVATE, $document->disk);
        Storage::disk('private')->assertExists($document->storage_path);
    }

    /**
     * Item 12 (continued): the existing account-setup mechanism, signed in
     * by the representative.
     */
    public function test_account_setup_completes_and_the_representative_can_sign_in(): void
    {
        $this->corporateCategory();
        $admin = $this->admin();
        $link = $this->captureSetupLinks();

        $this->post(route('membership.apply.store'), $this->corporatePayload())->assertRedirect();
        $application = MembershipApplication::query()->firstOrFail();
        $this->actingAs($admin)->post(route('admin.membership-applications.review', $application), ['decision' => 'approved', 'proof_reviewed' => '1']);
        $this->actingAs($admin)->post(route('admin.membership-applications.activate', $application), ['confirm' => '1']);

        $token = $this->tokenFromUrl($link->urls[0]);
        $this->assertTrue(app(CompleteAccountSetup::class)->handle($token, 'a-Strong-pass-phrase-1'));

        $member = User::query()->where('email', 'priya.fernando@example-airline.test')->firstOrFail();
        $this->assertSame('active', $member->fresh()->status);

        auth()->logout();
        $this->post('/login', ['email' => 'priya.fernando@example-airline.test', 'password' => 'a-Strong-pass-phrase-1'])
            ->assertRedirect('/');
        $this->assertAuthenticatedAs($member);
    }

    /**
     * Items 13–14: the digital membership card and its QR/verification
     * token — the exact same controller/view every category uses, and
     * still just one card for the one company membership.
     */
    public function test_digital_card_and_verification_work(): void
    {
        $membership = $this->activatedMembership(['full_name' => 'Priya Fernando'], 'C');

        $this->actingAs($membership->user)
            ->get(route('member.membership.card'))
            ->assertOk()
            ->assertSee('Priya Fernando')
            ->assertSee($membership->membership_number);

        $this->get(route('verification.show', ['token' => $membership->verification_token]))
            ->assertOk()
            ->assertSee('You are a member of Aviation Club International')
            ->assertSee('Priya Fernando')
            ->assertSee($membership->membership_number);
    }

    /**
     * Items 15–16: renewal after the introductory term uses the real,
     * seeded Corporate plan (LKR 30,000 / 12 months — docs/database/04 §4),
     * and the membership number never changes.
     */
    public function test_renewal_uses_the_corporate_plan_and_keeps_the_membership_number(): void
    {
        $membership = $this->renewableMembership(['full_name' => 'Priya Fernando'], 'C');
        $originalNumber = $membership->membership_number;
        $membership->terms->first()->forceFill(['starts_on' => now()->subMonths(4), 'expires_on' => now()->subDay()])->save();

        $this->actingAs($membership->user->fresh())
            ->post(route('member.membership.renewal.start'))
            ->assertRedirect(route('member.membership.show'));

        $term = DB::table('membership_terms')->where('status', 'pending_payment')->first();
        $this->assertSame('30000.00', $term->fee_amount);
        $this->assertSame(12, $term->duration_months);

        $payment = MembershipTerm::find($term->id)->payment;
        app(SubmitPaymentEvidence::class)->handle($payment, $membership->user, 'REF-CORPORATE-1', $this->pdfUpload());
        app(ConfirmPayment::class)->handle($payment->fresh(), $this->admin());

        $this->assertTrue($membership->fresh()->hasCurrentTerm());
        $this->assertSame($originalNumber, $membership->fresh()->membership_number, 'The membership number never changes across a renewal.');
        $this->assertSame(2, DB::table('membership_terms')->where('membership_id', $membership->id)->count());
        $this->assertSame(1, DB::table('memberships')->count(), 'Still exactly one membership — no per-seat memberships created by renewal.');
        $this->assertSame($membership->user_id, DB::table('memberships')->value('user_id'), 'The same one representative account, not a new one.');
    }
}
