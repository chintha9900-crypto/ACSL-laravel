{{--
    Admin's own dashboard (/admin, admin only). The operational section links
    are shared with the editor dashboard; below them, admin-only system
    administration: the role dashboards and editor invitations.
--}}
<x-layouts.admin title="Dashboard">
    <h1 class="font-display text-2xl font-bold text-primary md:text-3xl">Admin Dashboard</h1>
    <p class="mt-1 text-sm text-muted-foreground">
        Signed in as {{ auth()->user()->name }} &middot; {{ auth()->user()->roleModel?->label ?? auth()->user()->role }}
    </p>

    @include('admin.partials.section-links')

    <h2 class="mt-10 font-display text-lg font-bold text-primary">System administration</h2>

    <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <a href="{{ route('editor.dashboard') }}" class="ui-card block p-5 transition-colors hover:border-primary/60">
            <h3 class="font-display text-base font-semibold text-primary">Editor dashboard</h3>
            <p class="mt-1 text-sm text-muted-foreground">See the dashboard editors use.</p>
        </a>
        <a href="{{ route('dev.dashboard') }}" class="ui-card block p-5 transition-colors hover:border-primary/60">
            <h3 class="font-display text-base font-semibold text-primary">Dev dashboard</h3>
            <p class="mt-1 text-sm text-muted-foreground">System health and audit log.</p>
        </a>
    </div>

    @can('moderate', \App\Models\BlogComment::class)
        @php
            $pendingComments = \App\Models\BlogComment::query()->where('status', \App\Models\BlogComment::STATUS_PENDING)->with(['user:id,name', 'post:id,title,slug'])->oldest()->limit(5)->get();
            $pendingCommentCount = \App\Models\BlogComment::query()->where('status', \App\Models\BlogComment::STATUS_PENDING)->count();
        @endphp
        <section class="mt-6" aria-labelledby="pending-comments-heading">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h3 id="pending-comments-heading" class="font-display text-base font-semibold text-primary">Pending blog comments ({{ $pendingCommentCount }})</h3>
                @if ($pendingCommentCount > $pendingComments->count())
                    <a href="{{ route('admin.blog-comments.index') }}" class="text-sm text-primary underline underline-offset-2 hover:text-secondary">View all pending comments</a>
                @endif
            </div>
            @include('admin.blog-comments._list', ['comments' => $pendingComments])
        </section>
    @endcan

    @can('invite', \App\Models\User::class)
        <section class="ui-card mt-6 max-w-xl p-6" aria-labelledby="invite-heading">
            <h3 id="invite-heading" class="font-display text-base font-semibold text-primary">Invite an editor</h3>
            <p class="mt-1 text-sm text-muted-foreground">
                We email a secure, single-use link that expires. The editor creates their own password through it — no password is ever sent.
            </p>

            <form method="POST" action="{{ route('admin.editor-invitations.store') }}" class="mt-4 space-y-4">
                @csrf

                <div class="space-y-1.5">
                    <label for="invite-email" class="block text-sm font-medium leading-none">Email address</label>
                    <input id="invite-email" name="email" type="email" required maxlength="255" autocomplete="off" value="{{ old('email') }}" class="field-control" @error('email', 'invitation') aria-invalid="true" @enderror>
                    @error('email', 'invitation')
                        <p class="text-xs text-destructive">{{ $message }}</p>
                    @enderror
                </div>

                <div class="space-y-1.5">
                    <label for="invite-role" class="block text-sm font-medium leading-none">Role</label>
                    <select id="invite-role" name="role" required class="field-control">
                        <option value="{{ \App\Models\Role::EDITOR }}" selected>Editor</option>
                    </select>
                    @error('role', 'invitation')
                        <p class="text-xs text-destructive">{{ $message }}</p>
                    @enderror
                </div>

                <button type="submit" class="btn btn-lg btn-brand">Send invitation</button>
            </form>
        </section>
    @endcan
</x-layouts.admin>
