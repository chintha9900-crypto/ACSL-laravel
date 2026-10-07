@props(['title' => 'Admin'])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <title>{{ $title }} — Aviation Club International Admin</title>

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @endif
    </head>
    <body class="flex min-h-screen flex-col bg-muted font-sans text-foreground antialiased">
        <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:left-2 focus:top-2 focus:z-[60] focus:rounded-md focus:bg-background focus:px-3 focus:py-2 focus:text-sm">
            Skip to content
        </a>

        <header class="bg-primary text-primary-foreground">
            <div class="container mx-auto flex min-h-16 flex-wrap items-center justify-between gap-x-6 gap-y-2 px-4 py-3 lg:px-8">
                <div class="flex flex-wrap items-center gap-x-6 gap-y-1">
                    <span class="font-display text-lg font-bold">Aviation Club International <span class="font-normal text-primary-foreground/70">Admin</span></span>
                    {{--
                        RBAC foundation: every link is wrapped in the same `@can`
                        check its own page already enforces — hiding a link a role
                        has no permission for is UX only, never the real gate.
                    --}}
                    <nav aria-label="Admin" class="flex flex-wrap items-center gap-4">
                        <a href="{{ route('admin.dashboard') }}" class="text-sm font-medium text-primary-foreground/85 hover:text-secondary">Dashboard</a>
                        @can('viewAny', \App\Models\MembershipApplication::class)
                            <a href="{{ route('admin.membership-applications.index') }}" class="text-sm font-medium text-primary-foreground/85 hover:text-secondary">Membership applications</a>
                        @endcan
                        @can('viewAny', \App\Models\Payment::class)
                            <a href="{{ route('admin.payments.index') }}" class="text-sm font-medium text-primary-foreground/85 hover:text-secondary">Payments</a>
                        @endcan
                        @can('viewAny', \App\Models\BlogPost::class)
                            <a href="{{ route('admin.blog.index') }}" class="text-sm font-medium text-primary-foreground/85 hover:text-secondary">Blog posts</a>
                            <a href="{{ route('admin.blog-categories.index') }}" class="text-sm font-medium text-primary-foreground/85 hover:text-secondary">Blog categories</a>
                        @endcan
                        @can('viewAny', \App\Models\NewsItem::class)
                            <a href="{{ route('admin.news.index') }}" class="text-sm font-medium text-primary-foreground/85 hover:text-secondary">News</a>
                        @endcan
                        @can('viewAny', \App\Models\EventListing::class)
                            <a href="{{ route('admin.events.index') }}" class="text-sm font-medium text-primary-foreground/85 hover:text-secondary">Events</a>
                        @endcan
                        @can('viewAny', \App\Models\CsrProject::class)
                            <a href="{{ route('admin.csr.index') }}" class="text-sm font-medium text-primary-foreground/85 hover:text-secondary">CSR projects</a>
                        @endcan
                        @can('viewAny', \App\Models\CommercialPartner::class)
                            <a href="{{ route('admin.commercial-partners.index') }}" class="text-sm font-medium text-primary-foreground/85 hover:text-secondary">Commercial partners</a>
                        @endcan
                        @can('viewAny', \App\Models\Product::class)
                            <a href="{{ route('admin.products.index') }}" class="text-sm font-medium text-primary-foreground/85 hover:text-secondary">Products</a>
                            <a href="{{ route('admin.product-categories.index') }}" class="text-sm font-medium text-primary-foreground/85 hover:text-secondary">Product categories</a>
                        @endcan
                        @can('viewAny', \App\Models\Inventory::class)
                            <a href="{{ route('admin.inventory.index') }}" class="text-sm font-medium text-primary-foreground/85 hover:text-secondary">Inventory</a>
                        @endcan
                        @can('viewAny', \App\Models\Order::class)
                            <a href="{{ route('admin.orders.index') }}" class="text-sm font-medium text-primary-foreground/85 hover:text-secondary">Orders</a>
                        @endcan
                    </nav>
                </div>

                <form method="POST" action="{{ route('logout') }}" class="flex items-center gap-3 text-sm">
                    @csrf
                    <span class="text-primary-foreground/70">{{ auth()->user()->name }}</span>
                    <button type="submit" class="btn btn-sm border border-white/20 bg-white/10 hover:bg-white/20">Sign out</button>
                </form>
            </div>
        </header>

        <main id="main" class="container mx-auto flex-1 px-4 py-8 lg:px-8">
            @if (session('status'))
                <div class="mb-6 rounded-lg border border-secondary/40 bg-secondary/10 px-4 py-3 text-sm text-primary" role="status">{{ session('status') }}</div>
            @endif

            @if (session('warning'))
                <div class="mb-6 rounded-lg border border-destructive/50 bg-destructive/10 px-4 py-3 text-sm text-destructive" role="alert">{{ session('warning') }}</div>
            @endif

            {{ $slot }}
        </main>
    </body>
</html>
