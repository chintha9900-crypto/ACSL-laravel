{{--
    Public Jobs board. Desktop (lg): main board 75% + career sidebar 25%
    (fr units, so the split is of the width left after the gap). Inside the
    board, from md: job list 1/3 + details 2/3. Below md: search, list,
    sidebar; a job opens on its own page (jobs.show), which is also the
    no-JavaScript fallback everywhere.

    From md up, a small script swaps the details panel in place: it fetches
    the job's own page (same origin only), takes its sanitised
    [data-job-details] element via DOMParser and moves those nodes in — no
    innerHTML of untrusted strings, and nothing in it runs as script.
--}}
<x-layouts.public title="Aviation Jobs & Careers">
    <x-public.hero>
        <x-slot:badge>Jobs</x-slot:badge>
        <x-slot:lead>
            Browse current local and overseas aviation vacancies, and apply directly on the employer's website.
        </x-slot:lead>
        Aviation Jobs &amp; Careers
    </x-public.hero>

    <section class="border-t border-border">
        <div class="site-container grid gap-8 py-10 md:py-12 lg:grid-cols-[minmax(0,3fr)_minmax(0,1fr)]">
            <div class="min-w-0" data-job-board>
                @include('jobs._search', ['filters' => $filters])

                @if ($jobs->isEmpty())
                    <div class="ui-card mt-6 p-8 text-center">
                        @if ($hasAnyJobs)
                            <p class="font-display text-lg font-bold text-primary">No vacancies match your search.</p>
                            <p class="mt-2 text-sm text-muted-foreground">Try different keywords or another location.</p>
                            <a href="{{ route('jobs') }}" class="mt-4 inline-block text-sm font-semibold text-[#CC001F] hover:underline">Show all jobs</a>
                        @else
                            <p class="font-display text-lg font-bold text-primary">No current vacancies.</p>
                            <p class="mt-2 text-sm text-muted-foreground">Please check back soon.</p>
                        @endif
                    </div>
                @else
                    <p class="mt-5 text-sm text-muted-foreground" role="status">
                        {{ $jobs->total() }} {{ Str::plural('vacancy', $jobs->total()) }}{{ $filters['q'] !== '' ? ' for “'.$filters['q'].'”' : '' }}
                    </p>

                    <div class="mt-3 grid gap-6 md:grid-cols-[minmax(0,1fr)_minmax(0,2fr)] md:items-start">
                        <div class="min-w-0">
                            <ul data-job-list class="space-y-3 md:max-h-[calc(100vh-9rem)] md:overflow-y-auto md:pr-1 lg:max-h-[calc(100vh-11rem)]" aria-label="Job vacancies">
                                @foreach ($jobs as $job)
                                    @php($isSelected = $selected?->is($job))
                                    <li>
                                        <a href="{{ route('jobs.show', array_filter(['job' => $job->slug, ...$filters])) }}"
                                            data-job-link data-job-slug="{{ $job->slug }}"
                                            data-board-url="{{ route('jobs', array_filter([...$filters, 'job' => $job->slug, 'page' => $jobs->currentPage() > 1 ? $jobs->currentPage() : null])) }}"
                                            @if ($isSelected) aria-current="true" @endif
                                            class="group block rounded-lg border border-border bg-card p-4 transition-colors hover:border-[#CC001F]/50 hover:bg-muted/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring md:aria-[current=true]:border-[#CC001F] md:aria-[current=true]:bg-[#CC001F]/[0.04] md:aria-[current=true]:shadow-[inset_4px_0_0_#CC001F]">
                                            <span class="block font-display font-semibold leading-snug text-primary group-hover:underline">{{ $job->title }}</span>
                                            <span class="mt-1 block text-sm text-foreground">{{ $job->company_name }}</span>
                                            <span class="mt-0.5 block text-xs text-muted-foreground">{{ $job->location }}, {{ $job->country }}</span>
                                            <span class="mt-2 flex flex-wrap gap-1.5">
                                                <span class="rounded bg-muted px-2 py-0.5 text-xs font-medium text-foreground">{{ $job->employmentTypeLabel() }}</span>
                                                <span class="rounded bg-muted px-2 py-0.5 text-xs font-medium text-foreground">{{ $job->locationTypeLabel() }}</span>
                                            </span>
                                            <span class="mt-2 line-clamp-2 block text-sm text-muted-foreground">{{ $job->short_description }}</span>
                                            <span class="mt-2 block text-xs text-muted-foreground">Posted {{ ($job->date_posted ?? $job->published_at)?->format('j M Y') }}</span>
                                        </a>
                                    </li>
                                @endforeach
                            </ul>

                            @if ($jobs->hasPages())
                                <div class="mt-4">{{ $jobs->links('pagination::simple-tailwind') }}</div>
                            @endif
                        </div>

                        <div class="hidden min-w-0 md:block">
                            <div data-job-panel class="ui-card p-6 md:sticky md:top-28 md:max-h-[calc(100vh-9rem)] md:overflow-y-auto lg:top-[8.5rem] lg:max-h-[calc(100vh-11rem)] lg:p-8">
                                @include('jobs._details', ['job' => $selected, 'applyUrl' => $applyUrl])
                            </div>
                        </div>
                    </div>
                @endif
            </div>

            @include('jobs._sidebar')
        </div>
    </section>

    @if ($jobs->isNotEmpty())
        <script>
            (function () {
                var list = document.querySelector('[data-job-list]');
                var panel = document.querySelector('[data-job-panel]');
                if (!list || !panel || !window.fetch || !window.DOMParser || !window.history.pushState) { return; }
                var wide = window.matchMedia('(min-width: 768px)');
                var pending = null;

                function markSelected(slug) {
                    list.querySelectorAll('[data-job-link]').forEach(function (link) {
                        if (link.getAttribute('data-job-slug') === slug) { link.setAttribute('aria-current', 'true'); }
                        else { link.removeAttribute('aria-current'); }
                    });
                }

                function show(link, push) {
                    var url = new URL(link.href, window.location.href);
                    if (url.origin !== window.location.origin) { return; }
                    if (pending) { pending.abort(); }
                    pending = new AbortController();
                    panel.setAttribute('aria-busy', 'true');

                    fetch(url.href, { credentials: 'same-origin', headers: { 'Accept': 'text/html' }, signal: pending.signal })
                        .then(function (response) { if (!response.ok) { throw new Error('HTTP ' + response.status); } return response.text(); })
                        .then(function (html) {
                            var fresh = new DOMParser().parseFromString(html, 'text/html').querySelector('[data-job-details]');
                            if (!fresh) { throw new Error('No job details'); }
                            panel.replaceChildren(document.importNode(fresh, true));
                            panel.scrollTop = 0;
                            panel.removeAttribute('aria-busy');
                            markSelected(link.getAttribute('data-job-slug'));
                            if (push) { window.history.pushState({ jobSlug: link.getAttribute('data-job-slug') }, '', link.getAttribute('data-board-url')); }
                            var heading = panel.querySelector('h2');
                            if (heading) { heading.focus({ preventScroll: true }); }
                        })
                        .catch(function (error) {
                            if (error.name === 'AbortError') { return; }
                            window.location.href = url.href; // fall back to the job's own page
                        });
                }

                list.addEventListener('click', function (event) {
                    var link = event.target.closest('[data-job-link]');
                    if (!link || !wide.matches || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) { return; }
                    event.preventDefault();
                    show(link, true);
                });

                window.addEventListener('popstate', function () {
                    var slug = new URL(window.location.href).searchParams.get('job');
                    var link = slug ? list.querySelector('[data-job-link][data-job-slug="' + CSS.escape(slug) + '"]') : list.querySelector('[data-job-link]');
                    if (link) { show(link, false); } else { window.location.reload(); }
                });
            })();
        </script>
    @endif
</x-layouts.public>
