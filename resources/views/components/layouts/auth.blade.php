@props(['title' => 'Aviation Club International'])

{{-- Same controlled-logo-asset convention as layouts.public/member/admin
     (docs/frontend/08 C-02) — reused here, not reinvented. --}}
@php($hasLogo = file_exists(public_path('images/aci-logo.png')))

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex">
        {{-- Hardcoded, matching every other layout — never config('app.name'),
             which is Laravel's own unrelated scaffold default in .env and is
             never edited by this project (standing rule: .env is untouched). --}}
        <title>{{ $title }} — Aviation Club International</title>

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @endif
    </head>
    {{-- `.theme-public` (the same scope layouts.public uses) gives this page
         the approved red/dark-gray/gray/white tokens instead of the generic
         Tailwind "slate" palette it had before — reusing the existing design
         system rather than hand-picking new colours. --}}
    <body class="theme-public flex min-h-screen flex-col bg-background font-sans text-foreground antialiased">
        <main class="mx-auto flex min-h-screen w-full max-w-md flex-col justify-center px-4 py-10">
            <a href="{{ url('/') }}" class="mb-6 flex justify-center">
                @if ($hasLogo)
                    <img src="{{ asset('images/aci-logo.png') }}" alt="Aviation Club International" width="473" height="155" class="h-10 w-auto">
                @else
                    <span class="font-display text-lg font-bold text-primary">Aviation Club International</span>
                @endif
            </a>

            <div class="ui-card p-6 sm:p-8">
                {{ $slot }}
            </div>
        </main>
    </body>
</html>
