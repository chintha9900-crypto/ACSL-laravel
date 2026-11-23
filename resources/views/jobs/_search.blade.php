@use('App\Models\JobListing')
{{--
    Search box + All/Local/Overseas filter, spanning the full main board.
    Plain GET links and a GET form: the term is kept when the filter changes
    and the filter is kept when a new search is submitted.

    @param  array{q: string, location: string|null}  $filters
--}}
<div class="ui-card p-4 md:p-5">
    <form method="GET" action="{{ route('jobs') }}" role="search" class="flex flex-col gap-3 sm:flex-row">
        <label for="job-search" class="sr-only">Search jobs</label>
        <input id="job-search" type="search" name="q" value="{{ $filters['q'] }}" maxlength="100"
            placeholder="Job title, category, type or keyword"
            class="field-control h-11 min-w-0 flex-1 text-base">
        @if ($filters['location'])
            <input type="hidden" name="location" value="{{ $filters['location'] }}">
        @endif
        <button type="submit" class="btn btn-lg btn-brand h-11 px-6">Search</button>
    </form>

    <nav class="mt-4 flex flex-wrap items-center gap-2" aria-label="Job location">
        @foreach (['' => 'All', ...JobListing::LOCATION_TYPES] as $value => $label)
            @php($active = ($filters['location'] ?? '') === (string) $value)
            <a href="{{ route('jobs', array_filter(['q' => $filters['q'], 'location' => $value])) }}"
                @if ($active) aria-current="page" @endif
                @class([
                    'rounded-full border px-4 py-1.5 text-sm font-medium transition-colors',
                    'border-[#CC001F] bg-[#CC001F] text-white' => $active,
                    'border-border bg-background text-primary hover:border-[#CC001F]/60' => ! $active,
                ])>{{ $label }}</a>
        @endforeach
    </nav>
</div>
