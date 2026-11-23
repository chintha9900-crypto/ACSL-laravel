<?php

namespace App\Actions\Content;

use App\Exceptions\JobListingNotSavedException;
use App\Models\JobListing;

/**
 * Returns a published job to draft. Content, deadline, application URL and
 * `published_at` (a "last published" stamp, as UnpublishCsrProject) are kept.
 */
class UnpublishJobListing
{
    public function handle(JobListing $job): JobListing
    {
        if (! $job->forceFill(['status' => JobListing::STATUS_DRAFT])->save()) {
            throw new JobListingNotSavedException('The job could not be unpublished.');
        }

        return $job;
    }
}
