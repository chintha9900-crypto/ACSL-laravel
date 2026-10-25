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
            <div class="relative mt-1">
                <input id="password" name="password" type="password" required autocomplete="current-password"
                    class="field-control pr-10">
                {{-- Show/hide toggle. Hidden until the script below runs, so without
                     JavaScript there is no control that does nothing. --}}
                <button type="button" data-password-toggle aria-controls="password" aria-label="Show password" aria-pressed="false" hidden
                    class="absolute inset-y-0 right-0 flex items-center rounded-r-md px-3 text-muted-foreground hover:text-primary focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring">
                    <svg data-icon-show class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/></svg>
                    <svg data-icon-hide class="hidden h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.733 5.076a10.744 10.744 0 0 1 11.205 6.575 1 1 0 0 1 0 .696 10.747 10.747 0 0 1-1.444 2.49"/><path d="M14.084 14.158a3 3 0 0 1-4.242-4.242"/><path d="M17.479 17.499a10.75 10.75 0 0 1-15.417-5.151 1 1 0 0 1 0-.696 10.75 10.75 0 0 1 4.446-5.143"/><path d="m2 2 20 20"/></svg>
                </button>
            </div>
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

    <script>
        (function () {
            var toggle = document.querySelector('[data-password-toggle]');
            var input = document.getElementById('password');
            if (!toggle || !input) {
                return;
            }
            var showIcon = toggle.querySelector('[data-icon-show]');
            var hideIcon = toggle.querySelector('[data-icon-hide]');

            function setVisible(reveal) {
                input.type = reveal ? 'text' : 'password';
                toggle.setAttribute('aria-pressed', reveal ? 'true' : 'false');
                toggle.setAttribute('aria-label', reveal ? 'Hide password' : 'Show password');
                showIcon.classList.toggle('hidden', reveal);
                hideIcon.classList.toggle('hidden', !reveal);
            }

            toggle.hidden = false;
            toggle.addEventListener('click', function () {
                setVisible(input.type === 'password');
            });

            // Never submit or leave the page with the password readable on screen.
            input.form.addEventListener('submit', function () {
                setVisible(false);
            });
        })();
    </script>
</x-layouts.auth>
