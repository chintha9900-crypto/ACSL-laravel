{{--
    A job's own shareable page (/jobs/{slug}): the mobile and no-JavaScript
    view of a vacancy. Only publicly visible jobs reach it (404 otherwise).
    "Back to jobs" returns to the board with the same search and filter and
    this job selected.
--}}
<x-layouts.public :title="$job->title.' — Jobs'">
    <x-public.hero>
        <x-slot:badge>Jobs</x-slot:badge>
        <x-slot:lead>
            Browse current local and overseas aviation vacancies, and apply directly on the employer's website.
        </x-slot:lead>
        Aviation Jobs &amp; Careers
    </x-public.hero>

    <section class="border-t border-border">
        <div class="site-container grid gap-8 py-10 md:py-12 lg:grid-cols-[minmax(0,3fr)_minmax(0,1fr)]">
            <div class="min-w-0">
                @include('jobs._search', ['filters' => $filters])

                <a href="{{ route('jobs', array_filter([...$filters, 'job' => $job->slug])) }}" class="mt-6 inline-flex items-center gap-1.5 text-sm font-semibold text-[#CC001F] hover:underline">
                    <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
                    Back to jobs
                </a>

                <div class="ui-card mt-4 p-6 lg:p-8">
                    @include('jobs._details', ['job' => $job, 'applyUrl' => $applyUrl])
                </div>
            </div>

            @include('jobs._sidebar')
        </div>
    </section>
</x-layouts.public>
