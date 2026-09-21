<x-layouts.auth title="Create your password">
    <h1 class="text-xl font-semibold">Create your password</h1>
    <p class="mt-1 text-sm text-slate-600">Choose a password to finish setting up your account.</p>

    <form method="POST" action="{{ route('account.setup.store', ['token' => $token]) }}" class="mt-6 space-y-4">
        @csrf

        <div>
            <label for="password" class="block text-sm font-medium">Password</label>
            <input id="password" name="password" type="password" required autofocus autocomplete="new-password"
                class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none">
            @error('password')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="password_confirmation" class="block text-sm font-medium">Confirm password</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password"
                class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none">
        </div>

        <button type="submit" class="w-full rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">
            Create password
        </button>
    </form>
</x-layouts.auth>
