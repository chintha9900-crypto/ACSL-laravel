<x-layouts.auth title="Sign in">
    <h1 class="text-xl font-semibold">Welcome back</h1>
    <p class="mt-1 text-sm text-slate-600">Sign in to your account.</p>

    @if (session('status'))
        <p class="mt-4 rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{{ session('status') }}</p>
    @endif

    <form method="POST" action="{{ route('login') }}" class="mt-6 space-y-4">
        @csrf

        <div>
            <label for="email" class="block text-sm font-medium">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none">
            @error('email')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <div class="flex items-center justify-between">
                <label for="password" class="block text-sm font-medium">Password</label>
                <a href="{{ route('password.request') }}" class="text-sm text-slate-600 underline">Forgot?</a>
            </div>
            <input id="password" name="password" type="password" required autocomplete="current-password"
                class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none">
            @error('password')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="remember" value="1" class="rounded border-slate-300">
            Remember me
        </label>

        <button type="submit" class="w-full rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">
            Sign in
        </button>
    </form>
</x-layouts.auth>
