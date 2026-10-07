{{--
    Lands admin, editor and dev here after sign-in (RBAC foundation). Each
    card is wrapped in the same `@can` check the admin nav uses — hiding a
    section a role has no permission for is UX only; the real gate is each
    controller action's own Policy.
--}}
<x-layouts.admin title="Dashboard">
    <h1 class="font-display text-2xl font-bold text-primary md:text-3xl">Admin Dashboard</h1>
    <p class="mt-1 text-sm text-muted-foreground">
        Signed in as {{ auth()->user()->name }} &middot; {{ auth()->user()->roleModel?->label ?? auth()->user()->role }}
    </p>

    <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @can('viewAny', \App\Models\MembershipApplication::class)
            <a href="{{ route('admin.membership-applications.index') }}" class="ui-card block p-5 transition-colors hover:border-primary/60">
                <h2 class="font-display text-base font-semibold text-primary">Membership applications</h2>
                <p class="mt-1 text-sm text-muted-foreground">Review, approve, reject and activate.</p>
            </a>
        @endcan

        @can('viewAny', \App\Models\Payment::class)
            <a href="{{ route('admin.payments.index') }}" class="ui-card block p-5 transition-colors hover:border-primary/60">
                <h2 class="font-display text-base font-semibold text-primary">Payments</h2>
                <p class="mt-1 text-sm text-muted-foreground">Confirm or reject submitted payments.</p>
            </a>
        @endcan

        @can('viewAny', \App\Models\BlogPost::class)
            <a href="{{ route('admin.blog.index') }}" class="ui-card block p-5 transition-colors hover:border-primary/60">
                <h2 class="font-display text-base font-semibold text-primary">Blog</h2>
                <p class="mt-1 text-sm text-muted-foreground">Write, edit and publish posts.</p>
            </a>
        @endcan

        @can('viewAny', \App\Models\NewsItem::class)
            <a href="{{ route('admin.news.index') }}" class="ui-card block p-5 transition-colors hover:border-primary/60">
                <h2 class="font-display text-base font-semibold text-primary">News</h2>
                <p class="mt-1 text-sm text-muted-foreground">Write, edit and publish news items.</p>
            </a>
        @endcan

        @can('viewAny', \App\Models\EventListing::class)
            <a href="{{ route('admin.events.index') }}" class="ui-card block p-5 transition-colors hover:border-primary/60">
                <h2 class="font-display text-base font-semibold text-primary">Events</h2>
                <p class="mt-1 text-sm text-muted-foreground">Write, edit and publish events.</p>
            </a>
        @endcan

        @can('viewAny', \App\Models\CsrProject::class)
            <a href="{{ route('admin.csr.index') }}" class="ui-card block p-5 transition-colors hover:border-primary/60">
                <h2 class="font-display text-base font-semibold text-primary">CSR projects</h2>
                <p class="mt-1 text-sm text-muted-foreground">Write, edit and publish CSR projects.</p>
            </a>
        @endcan

        @can('viewAny', \App\Models\CommercialPartner::class)
            <a href="{{ route('admin.commercial-partners.index') }}" class="ui-card block p-5 transition-colors hover:border-primary/60">
                <h2 class="font-display text-base font-semibold text-primary">Commercial partners</h2>
                <p class="mt-1 text-sm text-muted-foreground">Manage the public partner listing.</p>
            </a>
        @endcan

        @can('viewAny', \App\Models\Product::class)
            <a href="{{ route('admin.products.index') }}" class="ui-card block p-5 transition-colors hover:border-primary/60">
                <h2 class="font-display text-base font-semibold text-primary">Products</h2>
                <p class="mt-1 text-sm text-muted-foreground">Manage the e-shop catalogue.</p>
            </a>
        @endcan

        @can('viewAny', \App\Models\Inventory::class)
            <a href="{{ route('admin.inventory.index') }}" class="ui-card block p-5 transition-colors hover:border-primary/60">
                <h2 class="font-display text-base font-semibold text-primary">Inventory</h2>
                <p class="mt-1 text-sm text-muted-foreground">Stock levels and low-stock thresholds.</p>
            </a>
        @endcan

        @can('viewAny', \App\Models\Order::class)
            <a href="{{ route('admin.orders.index') }}" class="ui-card block p-5 transition-colors hover:border-primary/60">
                <h2 class="font-display text-base font-semibold text-primary">Orders</h2>
                <p class="mt-1 text-sm text-muted-foreground">View and update order status.</p>
            </a>
        @endcan
    </div>

    @if (auth()->user()->hasPermission(\App\Support\Authorization\Ability::ViewAuditLog) || auth()->user()->hasPermission(\App\Support\Authorization\Ability::ViewDiagnostics))
        <p class="mt-8 text-sm text-muted-foreground">
            Audit log and diagnostics access is granted but has no screen yet — out of scope for this phase.
        </p>
    @endif
</x-layouts.admin>
