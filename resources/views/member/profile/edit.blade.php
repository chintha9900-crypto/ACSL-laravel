@use('Illuminate\Support\Facades\Storage')

<x-layouts.member :title="'Profile'">
    <h1 class="font-display text-2xl font-bold text-primary md:text-3xl">Your profile</h1>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        <section class="ui-card p-6 lg:col-span-2" aria-labelledby="profile-heading">
            <h2 id="profile-heading" class="font-display text-lg font-bold text-primary">Personal information</h2>

            <form method="POST" action="{{ route('member.profile.update') }}" enctype="multipart/form-data" class="mt-4 space-y-5">
                @csrf
                @method('PATCH')

                <div class="flex items-center gap-4">
                    <div class="h-16 w-16 shrink-0 overflow-hidden rounded-full bg-muted">
                        @if ($user->avatar_path)
                            <img src="{{ Storage::disk('public')->url($user->avatar_path) }}" alt="" class="h-full w-full object-cover">
                        @endif
                    </div>
                    <div class="min-w-0 flex-1 space-y-1.5">
                        <label for="avatar" class="block text-sm font-medium leading-none">Avatar</label>
                        <input
                            id="avatar"
                            name="avatar"
                            type="file"
                            accept="image/jpeg,image/png"
                            @error('avatar') aria-invalid="true" @enderror
                            class="field-control"
                        >
                        <p class="text-xs text-muted-foreground">JPG or PNG, max {{ number_format(config('uploads.avatar.max_kb') / 1024, 1) }} MB.</p>
                        @error('avatar')
                            <p class="text-xs text-destructive">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div class="grid gap-x-6 gap-y-5 sm:grid-cols-2">
                    <x-form.input name="name" label="Full name" :value="$user->name" />
                    <x-form.input name="phone" label="Phone" :required="false" :value="$user->phone" />
                    <x-form.input name="country" label="Country" :required="false" :value="$user->country" />
                    <x-form.input name="aviation_occupation" label="Aviation occupation" :required="false" :value="$user->aviation_occupation" />
                    <x-form.input name="job_title" label="Job title" :required="false" :value="$user->job_title" />
                    <x-form.input name="company" label="Company" :required="false" :value="$user->company" />
                    <x-form.input name="linkedin_url" label="LinkedIn URL" type="url" :required="false" :value="$user->linkedin_url" />
                </div>

                <x-form.input name="bio" label="Bio" type="textarea" :rows="4" :required="false" :value="$user->bio" />

                <button type="submit" class="btn btn-lg btn-gradient">Save changes</button>
            </form>
        </section>

        <aside class="ui-card h-fit p-6" aria-labelledby="account-heading">
            <h2 id="account-heading" class="font-display text-lg font-bold text-primary">Account</h2>

            <dl class="mt-4 space-y-4 text-sm">
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Email</dt>
                    <dd class="mt-1 break-all">{{ $user->email }}</dd>
                    <p class="mt-1 text-xs text-muted-foreground">Email cannot be changed here.</p>
                </div>

                @if ($membership)
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Membership number</dt>
                        <dd class="mt-1 font-mono font-semibold">{{ $membership->membership_number }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Category</dt>
                        <dd class="mt-1">{{ $membership->category->name }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Activated</dt>
                        <dd class="mt-1">{{ $membership->activated_on->format('j F Y') }}</dd>
                    </div>
                    <p class="text-xs text-muted-foreground">Membership details are read-only here.</p>
                @endif
            </dl>
        </aside>
    </div>
</x-layouts.member>
