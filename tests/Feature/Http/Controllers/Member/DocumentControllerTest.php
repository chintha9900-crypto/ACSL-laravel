<?php

namespace Tests\Feature\Http\Controllers\Member;

use App\Actions\Membership\StartRenewal;
use App\Actions\Payments\SubmitPaymentEvidence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesRenewals;
use Tests\Concerns\CreatesReviewableApplications;
use Tests\Concerns\CreatesUploads;
use Tests\MysqlTestCase;

class DocumentControllerTest extends MysqlTestCase
{
    use CreatesRenewals;
    use CreatesReviewableApplications;
    use CreatesUploads;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
    }

    private function evidenceDocument(string $email = 'nimal@example.test'): array
    {
        $membership = $this->renewableMembership(['email' => $email]);
        $term = app(StartRenewal::class)->handle($membership);
        $payment = app(SubmitPaymentEvidence::class)->handle(
            $term->payment,
            $membership->user,
            'REF-123',
            $this->realUpload('evidence.pdf', "%PDF-1.4\nmy proof\n"),
        );

        return [$membership, $payment->evidence->first()];
    }

    public function test_a_member_can_download_their_own_payment_evidence(): void
    {
        [$membership, $document] = $this->evidenceDocument();

        $response = $this->actingAs($membership->user)
            ->get(route('member.documents.show', $document))
            ->assertOk();

        $this->assertSame("%PDF-1.4\nmy proof\n", $response->streamedContent());
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('evidence.pdf', $response->headers->get('Content-Disposition'));
        $response->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Cache-Control', 'no-store, private');

        $audit = DB::table('audit_logs')->where('event', 'document.viewed')->get();
        $this->assertCount(1, $audit);
        $this->assertSame($membership->user_id, $audit[0]->user_id);
        $this->assertSame($document->id, $audit[0]->subject_id);
    }

    public function test_a_member_cannot_download_another_members_payment_evidence(): void
    {
        [$me] = $this->evidenceDocument('me@example.test');
        [, $theirDocument] = $this->evidenceDocument('them@example.test');

        $this->actingAs($me->user)
            ->get(route('member.documents.show', $theirDocument))
            ->assertForbidden();

        $this->assertSame(0, DB::table('audit_logs')->where('event', 'document.viewed')->count());
    }

    public function test_a_guest_cannot_download_payment_evidence(): void
    {
        [, $document] = $this->evidenceDocument();

        $this->get(route('member.documents.show', $document))->assertRedirect(route('login'));

        $this->assertSame(0, DB::table('audit_logs')->where('event', 'document.viewed')->count());
    }

    public function test_an_admin_can_still_download_a_members_payment_evidence_via_the_admin_route(): void
    {
        [, $document] = $this->evidenceDocument();

        $this->actingAs($this->admin())
            ->get(route('admin.documents.show', $document))
            ->assertOk();
    }
}
