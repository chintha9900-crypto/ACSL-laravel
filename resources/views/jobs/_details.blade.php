@use('App\Support\HtmlSanitizer')
@use('Illuminate\Support\Facades\Storage')
{{--
    A job's complete details, shared by the board's details panel and the
    job's own page. Rich-text fields are sanitised on save and again here
    (HtmlSanitizer::forDisplay); empty optional fields are not shown.
    Apply Now links straight to the employer's stored URL, only when it is
    a valid http(s) address ($applyUrl is null otherwise).

    @param  \App\Models\JobListing  $job
    @param  string|null  $applyUrl
--}}
<article data-job-details data-job-slug="{{ $job->slug }}" aria-labelledby="job-title-{{ $job->id }}" class="@container">
    {{-- Title and Apply Now sit side by side only when the details area itself
         is at least 28rem wide (a container query, so the board's narrower
         panel and the wider job page each get the layout that fits);
         otherwise the button goes below the title. --}}
    <header class="flex flex-col gap-4 border-b border-border pb-5 @md:flex-row @md:items-start @md:justify-between">
        <div class="flex min-w-0 items-start gap-4">
            @if ($job->company_logo)
                <img src="{{ Storage::disk('public')->url($job->company_logo) }}" alt="{{ $job->company_name }} logo"
                    class="h-14 w-14 shrink-0 rounded-md border border-border bg-white object-contain p-1">
            @endif
            <div class="min-w-0">
                <h2 id="job-title-{{ $job->id }}" tabindex="-1" class="font-display text-2xl font-bold text-primary focus:outline-none">{{ $job->title }}</h2>
                <p class="mt-1 font-medium text-foreground">{{ $job->company_name }}</p>
                <p class="mt-0.5 text-sm text-muted-foreground">{{ $job->location }}, {{ $job->country }} &middot; {{ $job->locationTypeLabel() }}</p>
            </div>
        </div>

        @if ($applyUrl)
            <a href="{{ $applyUrl }}" target="_blank" rel="noopener noreferrer" class="btn btn-lg btn-brand h-11 shrink-0 px-8">
                Apply Now<span class="sr-only"> (opens the employer's website in a new tab)</span>
            </a>
        @endif
    </header>

    <dl class="grid grid-cols-2 gap-x-6 gap-y-3 border-b border-border py-5 text-sm sm:grid-cols-3">
        @foreach (array_filter([
            'Employment type' => $job->employmentTypeLabel(),
            'Category' => $job->categoryLabel(),
            'Salary' => $job->salary,
            'Date posted' => ($job->date_posted ?? $job->published_at)?->format('j M Y'),
            'Apply by' => $job->application_deadline->format('j M Y'),
        ]) as $term => $detail)
            <div class="min-w-0">
                <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">{{ $term }}</dt>
                <dd class="mt-0.5 break-words font-medium text-foreground">{{ $detail }}</dd>
            </div>
        @endforeach
    </dl>

    <div class="space-y-6 pt-5">
        @foreach ([
            'Job description' => $job->description,
            'Responsibilities' => $job->responsibilities,
            'Required qualifications' => $job->qualifications,
            'Required experience' => $job->experience,
            'Additional requirements' => $job->additional_requirements,
        ] as $heading => $html)
            @if (filled($html))
                <section>
                    <h3 class="font-display text-lg font-bold text-primary">{{ $heading }}</h3>
                    <div class="blog-content mt-2">{!! HtmlSanitizer::forDisplay((string) $html) !!}</div>
                </section>
            @endif
        @endforeach
    </div>
</article>
