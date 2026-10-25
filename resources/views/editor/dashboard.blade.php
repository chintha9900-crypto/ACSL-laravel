{{--
    Editor's own dashboard (/editor). Links to the existing /admin/* sections
    the signed-in role holds a permission for; each section is Policy-gated.
--}}
<x-layouts.admin title="Editor Dashboard">
    <h1 class="font-display text-2xl font-bold text-primary md:text-3xl">Editor Dashboard</h1>
    <p class="mt-1 text-sm text-muted-foreground">
        Signed in as {{ auth()->user()->name }} &middot; {{ auth()->user()->roleModel?->label ?? auth()->user()->role }}
    </p>

    @include('admin.partials.section-links')
</x-layouts.admin>
