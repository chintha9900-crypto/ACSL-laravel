<?php

namespace Tests\Feature\Notifications\Membership;

use App\Models\MembershipCategory;
use App\Notifications\Membership\ApplicationRejected;
use App\Notifications\Membership\ApplicationSubmitted;
use App\Notifications\Membership\ApprovedIntroductory;
use App\Notifications\Membership\MoreDetailsRequested;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\Concerns\CreatesUploads;
use Tests\MysqlTestCase;

/**
 * Proves M1–M4 are wired to the real, already-existing business actions that
 * trigger them (submission and admin review) — not just that the Notification
 * classes render correctly in isolation.
 */
class MembershipEmailTriggersTest extends MysqlTestCase
{
    use CreatesReviewableApplications;
    use CreatesUploads;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
    }

    public function test_submitting_an_application_sends_m1_to_the_applicant(): void
    {
        Notification::fake();
        MembershipCategory::factory()->professional()->create();

        $this->post(route('membership.apply.store'), [
            'category' => 'P',
            'full_name' => 'Nimal Perera',
            'email' => 'nimal@example.test',
            'mobile' => '+94 77 123 4567',
            'address' => '12 Airport Road, Colombo',
            'aviation_role' => 'First Officer',
            'aviation_organisation' => 'Example Airlines',
            'proof_documents' => [$this->pdfUpload()],
        ])->assertSessionHasNoErrors()->assertRedirect();

        Notification::assertCount(1);
        Notification::assertSentOnDemand(
            ApplicationSubmitted::class,
            fn (ApplicationSubmitted $notification, array $channels, object $notifiable): bool => $notifiable->routes['mail'] === 'nimal@example.test'
        );
    }

    public function test_requesting_more_details_sends_m2_to_the_applicant(): void
    {
        Notification::fake();
        $application = $this->application('submitted', ['email' => 'nimal@example.test']);

        $this->actingAs($this->admin())->post(route('admin.membership-applications.review', $application), [
            'decision' => 'more_details_required',
            'request_message' => 'Please send a clearer copy of your licence.',
        ])->assertSessionHasNoErrors();

        Notification::assertCount(1);
        Notification::assertSentOnDemand(
            MoreDetailsRequested::class,
            fn (MoreDetailsRequested $notification, array $channels, object $notifiable): bool => $notifiable->routes['mail'] === 'nimal@example.test'
        );
    }

    public function test_approving_sends_m4_to_the_applicant(): void
    {
        Notification::fake();
        $application = $this->application('submitted', ['email' => 'nimal@example.test']);

        $this->actingAs($this->admin())->post(route('admin.membership-applications.review', $application), [
            'decision' => 'approved',
            'proof_reviewed' => '1',
        ])->assertSessionHasNoErrors();

        Notification::assertCount(1);
        Notification::assertSentOnDemand(ApprovedIntroductory::class);
    }

    public function test_rejecting_sends_m3_to_the_applicant(): void
    {
        Notification::fake();
        $application = $this->application('submitted', ['email' => 'nimal@example.test']);

        $this->actingAs($this->admin())->post(route('admin.membership-applications.review', $application), [
            'decision' => 'rejected',
        ])->assertSessionHasNoErrors();

        Notification::assertCount(1);
        Notification::assertSentOnDemand(ApplicationRejected::class);
    }

    public function test_a_failed_applicant_email_does_not_affect_the_recorded_decision(): void
    {
        Notification::shouldReceive('send')->andThrow(new \RuntimeException('SMTP down'));
        $application = $this->application('submitted');

        $this->actingAs($this->admin())
            ->post(route('admin.membership-applications.review', $application), ['decision' => 'rejected'])
            ->assertRedirect(route('admin.membership-applications.show', $application))
            ->assertSessionHas('status');

        $this->assertSame('rejected', $application->fresh()->status);
    }
}
