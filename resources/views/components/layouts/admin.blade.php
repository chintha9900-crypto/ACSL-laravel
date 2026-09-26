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
                    <nav aria-label="Admin" class="flex flex-wrap items-center gap-4">
                        <a href="{{ route('admin.membership-applications.index') }}" class="text-sm font-medium text-primary-foreground/85 hover:text-secondary">Membership applications</a>
                        <a href="{{ route('admin.payments.index') }}" class="text-sm font-medium text-primary-foreground/85 hover:text-secondary">Payments</a>
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
