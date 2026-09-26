@props(['status'])

@php
    $classes = match ($status) {
        'submitted' => 'border-secondary/40 bg-secondary/10 text-primary',
        'approved' => 'border-transparent bg-primary text-primary-foreground',
        'rejected' => 'border-destructive/50 text-destructive',
        default => 'border-border bg-muted text-foreground',
    };
@endphp

<span class="inline-flex items-center rounded-md border px-2.5 py-0.5 text-xs font-semibold {{ $classes }}">{{ \App\Models\MembershipApplication::labelFor($status) }}</span>
