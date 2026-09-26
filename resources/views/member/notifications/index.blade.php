<x-layouts.member :title="'Notifications'">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="font-display text-2xl font-bold text-primary md:text-3xl">Notifications</h1>
            <p class="mt-1 text-sm text-muted-foreground">{{ $unreadCount }} unread</p>
        </div>

        @if ($unreadCount > 0)
            <form method="POST" action="{{ route('member.notifications.mark-all-read') }}">
                @csrf
                <button type="submit" class="btn btn-sm border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground">Mark all read</button>
            </form>
        @endif
    </div>

    <div class="mt-6 space-y-3">
        @forelse ($notifications as $notification)
            @php($isUnread = $notification->read_at === null)

            <div @class(['ui-card p-4', 'border border-primary/40' => $isUnread])>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="font-semibold">{{ $notification->data['title'] ?? 'Notification' }}</p>
                            @if ($isUnread)
                                <span class="inline-flex items-center rounded-full bg-secondary/15 px-2 py-0.5 text-xs font-semibold text-primary">New</span>
                            @endif
                        </div>

                        @if (! empty($notification->data['message']))
                            <p class="mt-1 text-sm text-muted-foreground">{{ $notification->data['message'] }}</p>
                        @endif

                        <p class="mt-2 text-xs text-muted-foreground">{{ $notification->created_at->format('j F Y, H:i') }}</p>

                        @if (! empty($notification->data['action_url']))
                            <a href="{{ $notification->data['action_url'] }}" class="mt-2 inline-block text-sm text-primary underline underline-offset-2 hover:text-secondary">View</a>
                        @endif
                    </div>

                    @if ($isUnread)
                        <form method="POST" action="{{ route('member.notifications.mark-read', $notification->id) }}">
                            @csrf
                            <button type="submit" class="btn btn-sm border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground">Mark read</button>
                        </form>
                    @endif
                </div>
            </div>
        @empty
            <div class="ui-card p-6 text-center text-sm text-muted-foreground">No notifications.</div>
        @endforelse
    </div>

    <div class="mt-6">{{ $notifications->links() }}</div>
</x-layouts.member>
