<?php

namespace Tests\Concerns;

use App\Models\Document;
use App\Models\MembershipApplication;
use App\Models\MembershipCategory;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Admin users and stored applications with proof documents for the review tests.
 */
trait CreatesReviewableApplications
{
    protected function admin(): User
    {
        return User::factory()->active()->create(['role' => 'admin']);
    }

    protected function member(): User
    {
        return User::factory()->active()->create();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function application(string $state = 'submitted', array $attributes = [], string $categoryCode = 'P'): MembershipApplication
    {
        $category = MembershipCategory::query()->where('code', $categoryCode)->first()
            ?? MembershipCategory::factory()->{match ($categoryCode) {
                'S' => 'student',
                'V' => 'veteran',
                default => 'professional',
            }}()->create();

        $factory = MembershipApplication::factory()->for($category, 'category');

        $factory = match ($state) {
            'more_details_required' => $factory->moreDetailsRequired(),
            'approved' => $factory->approved(),
            'rejected' => $factory->rejected(),
            default => $factory,
        };

        return $factory->create($attributes);
    }

    /**
     * Store a proof file on the (fake) private disk and record its metadata.
     */
    protected function proofDocument(MembershipApplication $application, string $content = "%PDF-1.4\nproof\n", array $attributes = []): Document
    {
        $path = 'aviation-proof/'.$application->public_id.'/'.strtolower((string) Str::ulid()).'.pdf';
        Storage::disk('private')->put($path, $content);

        $document = Document::create([
            'kind' => Document::KIND_AVIATION_PROOF,
            'membership_application_id' => $application->id,
            'disk' => Document::DISK_PRIVATE,
            'storage_path' => $path,
            'original_filename' => 'employment-letter.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen($content),
            'checksum_sha256' => hash('sha256', $content),
        ]);

        // Columns that are not mass assignable (for example the purge tombstone).
        if ($attributes !== []) {
            $document->forceFill($attributes)->save();
        }

        return $document;
    }
}
