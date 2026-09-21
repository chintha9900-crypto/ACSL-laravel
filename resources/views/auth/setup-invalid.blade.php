<x-layouts.auth title="Link no longer valid">
    <h1 class="text-xl font-semibold">This link is no longer valid</h1>
    <p class="mt-2 text-sm text-slate-600">
        The account setup link has expired or has already been used. If you still need to set up your account, please contact the club for help.
    </p>

    <p class="mt-4 text-center text-sm"><a href="{{ route('login') }}" class="text-slate-600 underline">Go to sign in</a></p>
</x-layouts.auth>
