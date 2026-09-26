@props(['badge' => null, 'align' => 'center', 'lead' => null])

{{--
    Badge + H2 (+ optional lead paragraph) — docs/frontend/06 §3 `ui.section-heading`.
    Reused across the home page sections; the same shape will serve About,
    Benefits and the blog list once those pages exist.
--}}
<div {{ $attributes->class([$align === 'center' ? 'mx-auto max-w-2xl text-center' : 'max-w-2xl']) }}>
    @if ($badge)
        <span class="inline-flex items-center rounded-md border border-[#666666]/40 px-2.5 py-0.5 text-xs font-semibold uppercase tracking-wider text-[#4D4D4D]">
            {{ $badge }}
        </span>
    @endif

    <h2 class="{{ $badge ? 'mt-4' : '' }} font-display text-3xl font-bold tracking-tight text-primary md:text-4xl">
        {{ $slot }}
    </h2>

    @if ($lead)
        <p class="mt-4 text-lg leading-relaxed text-muted-foreground">{{ $lead }}</p>
    @endif
</div>
