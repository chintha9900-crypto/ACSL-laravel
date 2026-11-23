<?php

namespace App\Actions\Content;

use App\Exceptions\JobListingNotSavedException;
use App\Http\Requests\Admin\StoreJobListingRequest;
use App\Models\JobListing;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The explicit Publish action, for draft and expired jobs alike (an expired
 * job is republished only here, never by editing). Before publishing, the
 * stored record must have a valid http(s) application URL and a deadline of
 * today or later in Asia/Colombo. `published_at` is restamped on every
 * publish (as PublishCsrProject); `date_posted` is set to today only when
 * empty.
 */
class PublishJobListing
{
    /**
     * @throws ValidationException
     */
    public function handle(JobListing $job): JobListing
    {
        $today = JobListing::deadlineToday();

        Validator::make([
            'application_url' => $job->application_url,
            'application_deadline' => $job->application_deadline?->toDateString(),
        ], [
            'application_url' => ['required', ...StoreJobListingRequest::applicationUrlRules()],
            'application_deadline' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$today],
        ], [
            'application_url.required' => 'Add a valid application URL before publishing.',
            'application_url.url' => 'The application URL must be a full web address starting with http:// or https://.',
            'application_deadline.after_or_equal' => 'The application deadline has passed. Set a deadline of today or later (Asia/Colombo time) before publishing.',
        ])->validate();

        $saved = $job->forceFill([
            'status' => JobListing::STATUS_PUBLISHED,
            'published_at' => now(),
            'date_posted' => $job->date_posted ?? $today,
        ])->save();

        if (! $saved) {
            throw new JobListingNotSavedException('The job could not be published.');
        }

        return $job;
    }
}
