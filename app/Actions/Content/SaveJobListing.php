<?php

namespace App\Actions\Content;

use App\Exceptions\JobListingNotSavedException;
use App\Models\JobListing;
use App\Models\User;
use App\Support\HtmlSanitizer;
use Illuminate\Support\Str;

/**
 * Creates/updates a job listing's editable fields only. Mirrors
 * SaveCsrProject: status and published_at are never touched here (a new
 * job is a draft; changes only come from PublishJobListing/
 * UnpublishJobListing/`jobs:expire`), so editing — including extending an
 * expired job's deadline — never republishes it. Rich-text fields go
 * through HtmlSanitizer; an optional one left empty is stored as null.
 * The logo is handled separately (UpdateJobListingLogo). A save Eloquent
 * reports as not performed throws JobListingNotSavedException.
 */
class SaveJobListing
{
    /** @var list<string> */
    public const RICH_TEXT_FIELDS = ['description', 'responsibilities', 'qualifications', 'experience', 'additional_requirements'];

    /**
     * @param  array<string, mixed>  $data  Validated fields, without the logo.
     */
    public function create(array $data, User $creator): JobListing
    {
        $job = new JobListing($this->prepare($data));
        $saved = $job->forceFill([
            'slug' => $this->uniqueSlug((string) $data['title']),
            'status' => JobListing::STATUS_DRAFT,
            'created_by_user_id' => $creator->id,
        ])->save();

        if (! $saved) {
            throw new JobListingNotSavedException('The job could not be created.');
        }

        return $job;
    }

    /**
     * The slug is kept as first created, so a job's address stays stable.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(JobListing $job, array $data): JobListing
    {
        if (! $job->fill($this->prepare($data))->save()) {
            throw new JobListingNotSavedException('The job could not be updated.');
        }

        return $job;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function prepare(array $data): array
    {
        unset($data['slug'], $data['status'], $data['published_at'], $data['company_logo']);

        foreach (self::RICH_TEXT_FIELDS as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $clean = HtmlSanitizer::clean((string) $data[$field]);
            $data[$field] = trim(html_entity_decode(strip_tags($clean), ENT_QUOTES | ENT_HTML5, 'UTF-8')) === '' ? null : $clean;
        }

        foreach (['salary', 'application_url', 'date_posted'] as $optional) {
            if (array_key_exists($optional, $data) && trim((string) $data[$optional]) === '') {
                $data[$optional] = null;
            }
        }

        return $data;
    }

    private function uniqueSlug(string $title): string
    {
        $base = Str::limit(Str::slug($title), 200, '') ?: 'job';
        $slug = $base;

        for ($suffix = 2; JobListing::query()->where('slug', $slug)->exists(); $suffix++) {
            $slug = $base.'-'.$suffix;
        }

        return $slug;
    }
}
