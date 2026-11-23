<?php

namespace App\Http\Controllers;

use App\Http\Requests\Admin\StoreJobListingRequest;
use App\Models\JobListing;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * The public Jobs board (`/jobs`) and each job's own page (`/jobs/{slug}`).
 *
 * Only JobListing::scopePubliclyVisible() / isPubliclyVisible() decide what
 * the public sees: published, with a deadline of today or later in
 * Asia/Colombo — so a past-deadline job is hidden even before `jobs:expire`
 * has run. Search (`q`) and the Local/Overseas filter (`location`) are plain
 * query-string parameters, so results can be shared and work without
 * JavaScript. Applications are made on the employer's site only.
 */
class JobListingController extends Controller
{
    /** The longest search term accepted; anything longer is truncated. */
    private const MAX_SEARCH_LENGTH = 100;

    public function index(Request $request): View
    {
        $filters = $this->filters($request);

        /** @var LengthAwarePaginator<int, JobListing> $jobs */
        $jobs = $this->search($filters)
            ->orderByDesc('date_posted')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $requested = (string) $request->query('job', '');
        $selected = $jobs->getCollection()->firstWhere('slug', $requested) ?? $jobs->getCollection()->first();

        return view('jobs.index', [
            'jobs' => $jobs,
            'selected' => $selected,
            'applyUrl' => $selected ? $this->applyUrl($selected) : null,
            'filters' => $filters,
            'hasAnyJobs' => $jobs->total() > 0 || JobListing::query()->publiclyVisible()->exists(),
        ]);
    }

    public function show(Request $request, JobListing $job): View
    {
        if (! $job->isPubliclyVisible()) {
            abort(404);
        }

        return view('jobs.show', [
            'job' => $job,
            'applyUrl' => $this->applyUrl($job),
            'filters' => $this->filters($request),
        ]);
    }

    /**
     * @return array{q: string, location: string|null}
     */
    private function filters(Request $request): array
    {
        $location = $request->query('location');

        return [
            'q' => Str::limit(trim((string) $request->query('q', '')), self::MAX_SEARCH_LENGTH, ''),
            'location' => is_string($location) && array_key_exists($location, JobListing::LOCATION_TYPES) ? $location : null,
        ];
    }

    /**
     * Publicly visible jobs matching the search term and location filter.
     * The term matches title, short and full description, and the readable
     * category and employment-type labels (stored as codes, so the matching
     * codes are looked up from the model's label lists).
     *
     * @param  array{q: string, location: string|null}  $filters
     * @return Builder<JobListing>
     */
    private function search(array $filters): Builder
    {
        $term = $filters['q'];

        return JobListing::query()
            ->publiclyVisible()
            ->when($filters['location'], fn (Builder $query, string $location) => $query->where('location_type', $location))
            ->when($term !== '', function (Builder $query) use ($term): void {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $categories = $this->codesMatching(JobListing::CATEGORIES, $term);
                $types = $this->codesMatching(JobListing::EMPLOYMENT_TYPES, $term);

                $query->where(function (Builder $query) use ($like, $categories, $types): void {
                    $query->where('title', 'like', $like)
                        ->orWhere('short_description', 'like', $like)
                        ->orWhere('description', 'like', $like)
                        ->when($categories !== [], fn (Builder $query) => $query->orWhereIn('category', $categories))
                        ->when($types !== [], fn (Builder $query) => $query->orWhereIn('employment_type', $types));
                });
            });
    }

    /**
     * @param  array<string, string>  $labels
     * @return list<string>
     */
    private function codesMatching(array $labels, string $term): array
    {
        return array_keys(array_filter($labels, fn (string $label) => Str::contains($label, $term, ignoreCase: true)));
    }

    /**
     * The stored application URL, only if it still passes the same rule used
     * when publishing (http/https with a real host). Never constructed here.
     */
    private function applyUrl(JobListing $job): ?string
    {
        if ($job->application_url === null) {
            return null;
        }

        $valid = Validator::make(
            ['url' => $job->application_url],
            ['url' => ['required', ...StoreJobListingRequest::applicationUrlRules()]],
        )->passes();

        return $valid ? $job->application_url : null;
    }
}
