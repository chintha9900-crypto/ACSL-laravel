<?php

namespace App\Console\Commands;

use App\Models\JobListing;
use Illuminate\Console\Command;

/**
 * Marks published job listings whose application deadline date has fully
 * ended in Asia/Colombo as expired. Drafts, already-expired jobs and jobs
 * whose deadline is today are never touched, and nothing is deleted.
 *
 * Idempotent: a single conditional UPDATE, so repeated or overlapping runs
 * change nothing further. Public visibility does not depend on this command
 * (JobListing::scopePubliclyVisible() checks the deadline directly); it only
 * keeps the stored status accurate for the dashboards.
 */
class ExpireJobListings extends Command
{
    protected $signature = 'jobs:expire';

    protected $description = 'Expire published job listings whose application deadline has passed (Asia/Colombo)';

    public function handle(): int
    {
        $expired = JobListing::query()
            ->published()
            ->whereDate('application_deadline', '<', JobListing::deadlineToday())
            ->update(['status' => JobListing::STATUS_EXPIRED]);

        $this->info("Expired {$expired} job listing(s).");

        return self::SUCCESS;
    }
}
