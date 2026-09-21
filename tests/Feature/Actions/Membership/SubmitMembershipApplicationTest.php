<?php

namespace Tests\Feature\Actions\Membership;

use App\Actions\Membership\SubmitMembershipApplication;
use App\Models\Document;
use App\Models\MembershipApplication;
use App\Models\MembershipCategory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\Concerns\CreatesUploads;
use Tests\MysqlTestCase;

class SubmitMembershipApplicationTest extends MysqlTestCase
{
    use CreatesUploads;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function applicationData(MembershipCategory $category, array $overrides = []): array
    {
        return [
            'membership_category_id' => $category->id,
            'full_name' => 'Nimal Perera',
            'email' => 'nimal.perera@example.test',
            'mobile' => '+94 77 123 4567',
            'address' => '12 Airport Road, Colombo',
            'aviation_role' => 'First Officer',
            'aviation_organisation' => 'Example Airlines',
            ...$overrides,
        ];
    }

    /**
     * @return list<UploadedFile>
     */
    private function proofFiles(int $count): array
    {
        return array_map(
            fn (int $i) => $this->pdfUpload("proof-{$i}.pdf"),
            range(1, $count),
        );
    }

    private function assertNothingPersisted(): void
    {
        foreach (['membership_applications', 'documents', 'membership_status_history', 'audit_logs'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "{$table} must be empty after a failed submission.");
        }

        $this->assertSame([], Storage::disk('private')->allFiles(), 'No proof file may remain after a failed submission.');
    }

    public function test_creates_the_application_documents_and_history_together(): void
    {
        $category = MembershipCategory::factory()->professional()->create();

        $application = app(SubmitMembershipApplication::class)->handle($this->applicationData($category), $this->proofFiles(2));

        $this->assertSame('submitted', $application->fresh()->status);
        $this->assertSame(2, Document::query()->where('membership_application_id', $application->id)->count());
        $this->assertSame(1, DB::table('membership_status_history')->where('membership_application_id', $application->id)->count());
        $this->assertSame(1, DB::table('audit_logs')->where('subject_id', $application->id)->count());
        $this->assertCount(2, Storage::disk('private')->allFiles());
    }

    public function test_rolls_back_the_application_and_removes_stored_files_when_a_later_document_fails(): void
    {
        $category = MembershipCategory::factory()->professional()->create();
        $created = 0;

        Document::creating(function () use (&$created): void {
            if (++$created === 2) {
                throw new RuntimeException('Simulated failure while saving the second document.');
            }
        });

        try {
            app(SubmitMembershipApplication::class)->handle($this->applicationData($category), $this->proofFiles(2));
            $this->fail('The simulated failure should have been rethrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated failure while saving the second document.', $exception->getMessage());
        }

        $this->assertNothingPersisted();
    }

    public function test_rolls_back_everything_when_the_first_document_fails(): void
    {
        $category = MembershipCategory::factory()->professional()->create();

        Document::creating(fn () => throw new RuntimeException('Simulated failure.'));

        $this->assertThrows(
            fn () => app(SubmitMembershipApplication::class)->handle($this->applicationData($category), $this->proofFiles(1)),
            RuntimeException::class,
        );

        $this->assertNothingPersisted();
    }

    public function test_database_backstop_turns_a_concurrent_duplicate_into_a_validation_error_and_cleans_up(): void
    {
        $category = MembershipCategory::factory()->professional()->create();
        MembershipApplication::factory()->for($category, 'category')->create(['email' => 'nimal.perera@example.test']);
        $existing = DB::table('membership_applications')->count();

        try {
            app(SubmitMembershipApplication::class)->handle($this->applicationData($category), $this->proofFiles(1));
            $this->fail('A duplicate open application must be refused by the unique open_email_key.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('email', $exception->errors());
        }

        $this->assertSame($existing, DB::table('membership_applications')->count());
        $this->assertSame(0, DB::table('documents')->count());
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    public function test_refuses_a_file_whose_detected_type_is_not_allowed_even_if_validation_was_bypassed(): void
    {
        $category = MembershipCategory::factory()->professional()->create();
        $script = $this->realUpload('proof.pdf', '<?php echo 1;');

        $this->assertThrows(
            fn () => app(SubmitMembershipApplication::class)->handle($this->applicationData($category), [$script]),
            RuntimeException::class,
        );

        $this->assertNothingPersisted();
    }

    public function test_status_cannot_be_set_through_the_data_array(): void
    {
        $category = MembershipCategory::factory()->professional()->create();

        $application = app(SubmitMembershipApplication::class)->handle(
            $this->applicationData($category, ['status' => 'approved', 'user_id' => 1, 'decided_at' => now()]),
            $this->proofFiles(1),
        );

        $fresh = $application->fresh();
        $this->assertSame('submitted', $fresh->status);
        $this->assertNull($fresh->user_id);
        $this->assertNull($fresh->decided_at);
    }
}
