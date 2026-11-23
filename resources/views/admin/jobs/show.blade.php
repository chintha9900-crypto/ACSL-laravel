@use('App\Support\HtmlSanitizer')
@use('Illuminate\Support\Facades\Storage')
<x-layouts.admin :title="$job->title">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <h1 class="font-display text-2xl font-bold text-primary md:text-3xl">{{ $job->title }}</h1>
        <div class="flex flex-wrap gap-2">
            @include('admin.jobs._actions', ['job' => $job])
            <a href="{{ route('admin.jobs.index') }}" class="btn btn-sm border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground">Back to jobs</a>
        </div>
    </div>

    @include('admin.jobs._errors')

    <div class="ui-card mt-6 grid gap-8 p-6 md:grid-cols-3 md:p-8">
        <dl class="space-y-4 text-sm">
            @if ($job->company_logo)
                <div>
                    <dt class="sr-only">Logo</dt>
                    <dd><img src="{{ Storage::disk('public')->url($job->company_logo) }}" alt="{{ $job->company_name }} logo" class="h-16 w-auto rounded-md border border-border bg-white object-contain p-1"></dd>
                </div>
            @endif
            @foreach ([
                'Company' => $job->company_name,
                'Category' => $job->categoryLabel(),
                'Employment type' => $job->employmentTypeLabel(),
                'Location' => $job->location.', '.$job->country.' ('.$job->locationTypeLabel().')',
                'Salary' => $job->salary ?? '—',
                'Date posted' => $job->date_posted?->format('j F Y') ?? '—',
                'Visible until (Asia/Colombo)' => $job->application_deadline->format('j F Y'),
                'Last published' => $job->published_at?->format('j F Y, g:ia') ?? '—',
            ] as $term => $detail)
                <div>
                    <dt class="font-medium text-muted-foreground">{{ $term }}</dt>
                    <dd>{{ $detail }}</dd>
                </div>
            @endforeach
            <div>
                <dt class="font-medium text-muted-foreground">Status</dt>
                <dd>@include('admin.jobs._status-badge', ['job' => $job])</dd>
            </div>
            <div>
                <dt class="font-medium text-muted-foreground">Application URL</dt>
                <dd class="break-all">
                    @if ($job->application_url)
                        <a href="{{ $job->application_url }}" target="_blank" rel="noopener noreferrer" class="text-primary underline underline-offset-2 hover:text-secondary">{{ $job->application_url }}</a>
                    @else
                        <span class="text-[#CC001F]">Not set — required to publish</span>
                    @endif
                </dd>
            </div>
        </dl>

        <div class="space-y-6 md:col-span-2">
            <div>
                <h2 class="font-display text-base font-semibold text-primary">Short description</h2>
                <p class="mt-1 text-sm text-foreground/85">{{ $job->short_description }}</p>
            </div>
            @foreach ([
                'Full job description' => $job->description,
                'Responsibilities' => $job->responsibilities,
                'Required qualifications' => $job->qualifications,
                'Required experience' => $job->experience,
                'Additional requirements' => $job->additional_requirements,
            ] as $heading => $html)
                @if ($html)
                    <div>
                        <h2 class="font-display text-base font-semibold text-primary">{{ $heading }}</h2>
                        <div class="blog-content mt-1 text-sm">{!! HtmlSanitizer::forDisplay((string) $html) !!}</div>
                    </div>
                @endif
            @endforeach
        </div>
    </div>
</x-layouts.admin>
