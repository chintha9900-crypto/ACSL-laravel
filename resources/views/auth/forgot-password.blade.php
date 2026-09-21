<x-layouts.auth title="Forgot password">
    <h1 class="text-xl font-semibold">Forgot your password?</h1>
    <p class="mt-1 text-sm text-slate-600">Enter your email and we will send a reset link if an account exists.</p>

    @if (session('status'))
        <p class="mt-4 rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{{ session('status') }}</p>
    @endif

    <form method="POST" action="{{ route('password.email') }}" class="mt-6 space-y-4">
        @csrf

        <div>
            <label for="email" class="block text-sm font-medium">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none">
            @error('email')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <button type="submit" class="w-full rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">
            Send reset link
        </button>
    </form>

    <p class="mt-4 text-center text-sm"><a href="{{ route('login') }}" class="text-slate-600 underline">Back to sign in</a></p>
</x-layouts.auth>
