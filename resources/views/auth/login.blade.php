<x-layouts.auth title="Sign in">
    <h1 class="font-display text-xl font-bold text-primary">Welcome back</h1>
    <p class="mt-1 text-sm text-muted-foreground">Sign in to your account.</p>

    @if (session('status'))
        <p class="mt-4 rounded-lg border border-[#666666]/30 bg-muted px-3 py-2 text-sm text-primary" role="status">{{ session('status') }}</p>
    @endif

    <form method="POST" action="{{ route('login') }}" class="mt-6 space-y-4">
        @csrf

        <div>
            <label for="email" class="block text-sm font-medium">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                class="field-control mt-1">
            @error('email')
                <p class="mt-1 text-sm text-[#CC001F]">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <div class="flex items-center justify-between">
                <label for="password" class="block text-sm font-medium">Password</label>
                <a href="{{ route('password.request') }}" class="text-sm text-[#CC001F] hover:underline">Forgot?</a>
            </div>
            <input id="password" name="password" type="password" required autocomplete="current-password"
                class="field-control mt-1">
            @error('password')
                <p class="mt-1 text-sm text-[#CC001F]">{{ $message }}</p>
            @enderror
        </div>

        <label class="flex items-center gap-2 text-sm text-foreground">
            <input type="checkbox" name="remember" value="1" class="rounded border-input">
            Remember me
        </label>

        <button type="submit" class="btn btn-lg btn-brand w-full">
            Sign in
        </button>
    </form>
</x-layouts.auth>
