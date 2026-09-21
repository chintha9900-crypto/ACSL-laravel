@props(['badge', 'lead'])

<section class="relative bg-primary text-primary-foreground">
    <div class="gradient-hero absolute inset-0 opacity-95"></div>
    <div class="container relative mx-auto px-4 py-20 text-center md:py-24 lg:px-8">
        <span class="mb-5 inline-flex items-center rounded-md border border-white/20 bg-white/10 px-2.5 py-0.5 text-xs font-semibold">
            {{ $badge }}
        </span>
        <h1 class="font-display text-4xl font-bold md:text-6xl">{{ $slot }}</h1>
        <p class="mx-auto mt-5 max-w-2xl text-primary-foreground/85">{{ $lead }}</p>
    </div>
</section>
