<x-layouts.admin title="Events">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <h1 class="font-display text-2xl font-bold text-primary md:text-3xl">Events</h1>
        <a href="{{ route('admin.events.create') }}" class="btn btn-lg btn-brand">New event</a>
    </div>

    <nav class="mt-4 flex flex-wrap gap-2" aria-label="Filter by status">
        @foreach ([null => 'All', 'draft' => 'Draft', 'published' => 'Published'] as $value => $label)
            @php($active = ($status ?? null) === ($value ?: null))
            <a href="{{ route('admin.events.index', array_filter(['status' => $value])) }}"
                @if ($active) aria-current="true" @endif
                @class([
                    'rounded-md border px-3 py-1.5 text-sm font-medium transition-colors',
                    'border-primary bg-primary text-primary-foreground' => $active,
                    'border-border bg-background text-primary hover:border-primary/60' => ! $active,
                ])>{{ $label }}</a>
        @endforeach
    </nav>

    @if (session('status'))
        <p class="mt-4 rounded-lg border border-secondary/40 bg-secondary/10 px-4 py-3 text-sm text-primary" role="status">{{ session('status') }}</p>
    @endif

    <div class="ui-card mt-6 overflow-x-auto">
        <table class="w-full min-w-[48rem] text-left text-sm">
            <thead class="border-b border-border text-xs uppercase tracking-wide text-muted-foreground">
                <tr>
                    <th scope="col" class="px-4 py-3 font-medium">Title</th>
                    <th scope="col" class="px-4 py-3 font-medium">Starts</th>
                    <th scope="col" class="px-4 py-3 font-medium">Location</th>
                    <th scope="col" class="px-4 py-3 font-medium">Status</th>
                    <th scope="col" class="px-4 py-3 font-medium">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($events as $event)
                    <tr>
                        <td class="px-4 py-3">
                            <a href="{{ route('admin.events.show', $event) }}" class="font-medium text-primary underline underline-offset-2 hover:text-secondary">{{ $event->title }}</a>
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap">{{ $event->starts_at->format('j M Y, g:ia') }}</td>
                        <td class="px-4 py-3">{{ $event->location ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center rounded-md border px-2.5 py-0.5 text-xs font-semibold {{ $event->status === 'published' ? 'border-transparent bg-primary text-primary-foreground' : 'border-border bg-muted text-foreground' }}">
                                {{ ucfirst($event->status) }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap gap-2">
                                <a href="{{ route('admin.events.edit', $event) }}" class="btn btn-sm border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground">Edit</a>

                                @if ($event->status === 'published')
                                    <form method="POST" action="{{ route('admin.events.unpublish', $event) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-sm border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground">Unpublish</button>
                                    </form>
                                @else
                                    <form method="POST" action="{{ route('admin.events.publish', $event) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-brand">Publish</button>
                                    </form>
                                @endif

                                <form method="POST" action="{{ route('admin.events.destroy', $event) }}" onsubmit="return confirm('Delete this event? This cannot be undone.');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm border border-[#CC001F]/40 text-[#CC001F] hover:bg-[#CC001F]/5">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-muted-foreground">No events found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">{{ $events->links() }}</div>
</x-layouts.admin>
