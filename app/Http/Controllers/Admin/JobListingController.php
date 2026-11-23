<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Content\PublishJobListing;
use App\Actions\Content\SaveJobListing;
use App\Actions\Content\UnpublishJobListing;
use App\Actions\Content\UpdateJobListingLogo;
use App\Exceptions\JobListingNotSavedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreJobListingRequest;
use App\Http\Requests\Admin\UpdateJobListingRequest;
use App\Models\JobListing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * Admin and editor Jobs management, modelled on Admin\CsrController.
 * Saving never changes a job's status; Publish/Unpublish are explicit
 * actions, and the public visibility rule is never applied here, so draft,
 * published and expired jobs are all listed.
 */
class JobListingController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', JobListing::class);

        $status = $request->query('status');
        $status = is_string($status) && array_key_exists($status, JobListing::STATUSES) ? $status : null;
        $search = trim((string) $request->query('q', ''));

        $jobs = JobListing::query()
            ->select(['id', 'title', 'company_name', 'category', 'employment_type', 'location', 'country', 'application_deadline', 'date_posted', 'status', 'created_at'])
            ->when($status, fn ($query) => $query->where('status', $status))
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $query->where('title', 'like', $like)->orWhere('company_name', 'like', $like);
            }))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return view('admin.jobs.index', [
            'jobs' => $jobs,
            'status' => $status,
            'search' => $search,
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', JobListing::class);

        return view('admin.jobs.create');
    }

    /**
     * The job and its logo are saved in one transaction: if either is not
     * saved, nothing is kept and the form is shown again with an error.
     */
    public function store(StoreJobListingRequest $request, SaveJobListing $save, UpdateJobListingLogo $updateLogo): RedirectResponse
    {
        try {
            DB::transaction(function () use ($request, $save, $updateLogo): void {
                $job = $save->create($request->safe()->except(['company_logo']), $request->user());

                if ($request->hasFile('company_logo')) {
                    $updateLogo->handle($job, $request->file('company_logo'));
                }
            });
        } catch (JobListingNotSavedException) {
            return $this->notSaved('The job could not be saved. Nothing was changed — please try again.');
        }

        return redirect()->route('admin.jobs.index')->with('status', 'Job created as a draft.');
    }

    public function show(JobListing $job): View
    {
        Gate::authorize('view', $job);

        return view('admin.jobs.show', ['job' => $job]);
    }

    public function edit(JobListing $job): View
    {
        Gate::authorize('update', $job);

        return view('admin.jobs.edit', ['job' => $job]);
    }

    /**
     * Fields and logo change are saved in one transaction. If any part is
     * not saved, the whole edit is rolled back, the old logo is kept (its
     * deletion only runs after commit) and any new upload is removed.
     */
    public function update(UpdateJobListingRequest $request, JobListing $job, SaveJobListing $save, UpdateJobListingLogo $updateLogo): RedirectResponse
    {
        try {
            $message = DB::transaction(function () use ($request, $job, $save, $updateLogo): string {
                $save->update($job, $request->safe()->except(['company_logo', 'remove_company_logo']));

                // A newly uploaded logo takes priority over "Remove current logo".
                if ($request->hasFile('company_logo')) {
                    $updateLogo->handle($job, $request->file('company_logo'));
                } elseif ($request->boolean('remove_company_logo') && $job->company_logo !== null) {
                    $updateLogo->remove($job);

                    return 'Job updated and logo removed.';
                }

                return 'Job updated.';
            });
        } catch (JobListingNotSavedException) {
            return $this->notSaved('The job could not be updated. Your changes were not saved — please try again.');
        }

        return redirect()->route('admin.jobs.index')->with('status', $message);
    }

    /**
     * The record is deleted first, so a failed delete never leaves it
     * pointing at a removed logo file.
     */
    public function destroy(JobListing $job): RedirectResponse
    {
        Gate::authorize('delete', $job);

        $logo = $job->company_logo;

        if (! $job->delete()) {
            return $this->notSaved('The job could not be deleted. Please try again.');
        }

        if ($logo !== null) {
            Storage::disk('public')->delete($logo);
        }

        return redirect()->route('admin.jobs.index')->with('status', 'Job deleted.');
    }

    public function publish(JobListing $job, PublishJobListing $publish): RedirectResponse
    {
        Gate::authorize('publish', $job);

        try {
            $publish->handle($job);
        } catch (JobListingNotSavedException) {
            return $this->notSaved('The job could not be published. Please try again.');
        }

        return redirect()->back(fallback: route('admin.jobs.index'))->with('status', 'Job published.');
    }

    public function unpublish(JobListing $job, UnpublishJobListing $unpublish): RedirectResponse
    {
        Gate::authorize('publish', $job);

        try {
            $unpublish->handle($job);
        } catch (JobListingNotSavedException) {
            return $this->notSaved('The job could not be unpublished. Please try again.');
        }

        return redirect()->back(fallback: route('admin.jobs.index'))->with('status', 'Job unpublished and returned to draft.');
    }

    /**
     * Back to the previous page with the form input and an error, and no
     * success message.
     */
    private function notSaved(string $message): RedirectResponse
    {
        return redirect()->back(fallback: route('admin.jobs.index'))->withInput()->withErrors(['job' => $message]);
    }
}
