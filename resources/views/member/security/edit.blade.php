<x-layouts.member :title="'Security'">
    <h1 class="font-display text-2xl font-bold text-primary md:text-3xl">Security</h1>

    <section class="ui-card mt-6 max-w-lg p-6" aria-labelledby="security-heading">
        <h2 id="security-heading" class="font-display text-lg font-bold text-primary">Change password</h2>

        <form method="POST" action="{{ route('member.security.update') }}" class="mt-4 space-y-5" autocomplete="off">
            @csrf
            @method('PATCH')

            <div class="space-y-1.5">
                <label for="current_password" class="block text-sm font-medium leading-none">Current password</label>
                <input
                    id="current_password"
                    name="current_password"
                    type="password"
                    required
                    autocomplete="current-password"
                    @error('current_password') aria-invalid="true" @enderror
                    class="field-control"
                >
                @error('current_password')
                    <p class="text-xs text-destructive">{{ $message }}</p>
                @enderror
            </div>

            <div class="space-y-1.5">
                <label for="password" class="block text-sm font-medium leading-none">New password</label>
                <input
                    id="password"
                    name="password"
                    type="password"
                    required
                    autocomplete="new-password"
                    @error('password') aria-invalid="true" @enderror
                    class="field-control"
                >
                <p class="text-xs text-muted-foreground">At least 8 characters.</p>
                @error('password')
                    <p class="text-xs text-destructive">{{ $message }}</p>
                @enderror
            </div>

            <div class="space-y-1.5">
                <label for="password_confirmation" class="block text-sm font-medium leading-none">Confirm new password</label>
                <input
                    id="password_confirmation"
                    name="password_confirmation"
                    type="password"
                    required
                    autocomplete="new-password"
                    class="field-control"
                >
            </div>

            <button type="submit" class="btn btn-lg btn-gradient">Update password</button>
        </form>
    </section>
</x-layouts.member>
