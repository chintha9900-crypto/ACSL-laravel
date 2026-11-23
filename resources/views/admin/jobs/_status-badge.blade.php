{{-- Status pill for a job listing, matching the CSR badge style. --}}
<span @class([
    'inline-flex items-center rounded-md border px-2.5 py-0.5 text-xs font-semibold',
    'border-transparent bg-primary text-primary-foreground' => $job->status === \App\Models\JobListing::STATUS_PUBLISHED,
    'border-[#CC001F]/40 bg-[#CC001F]/5 text-[#CC001F]' => $job->status === \App\Models\JobListing::STATUS_EXPIRED,
    'border-border bg-muted text-foreground' => $job->status === \App\Models\JobListing::STATUS_DRAFT,
])>{{ $job->statusLabel() }}</span>
@if ($job->status === \App\Models\JobListing::STATUS_PUBLISHED && $job->isPastDeadline())
    <span class="mt-1 block text-xs text-muted-foreground">Deadline passed — hidden publicly</span>
@endif
