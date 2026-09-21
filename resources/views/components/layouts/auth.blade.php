@props(['title' => 'Aviation Club International'])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex">
        <title>{{ $title }} — {{ config('app.name') }}</title>

        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @endif
    </head>
    <body class="min-h-screen bg-slate-50 font-sans text-slate-900 antialiased">
        <main class="mx-auto flex min-h-screen w-full max-w-md flex-col justify-center px-4 py-10">
            <a href="{{ url('/') }}" class="mb-6 text-center text-sm font-semibold uppercase tracking-wide text-slate-600">
                {{ config('app.name') }}
            </a>

            <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                {{ $slot }}
            </div>
        </main>
    </body>
</html>
