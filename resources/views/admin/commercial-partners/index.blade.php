@use('Illuminate\Support\Facades\Storage')
<x-layouts.admin title="Commercial partners">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <h1 class="font-display text-2xl font-bold text-primary md:text-3xl">Commercial partners</h1>
        <a href="{{ route('admin.commercial-partners.create') }}" class="btn btn-lg btn-brand">New partner</a>
    </div>

    <nav class="mt-4 flex flex-wrap gap-2" aria-label="Filter by status">
        @foreach ([null => 'All', '1' => 'Active', '0' => 'Inactive'] as $value => $label)
            @php($isActive = ($active ?? null) === ($value ?: null))
            <a href="{{ route('admin.commercial-partners.index', array_filter(['active' => $value], fn ($v) => $v !== null)) }}"
                @if ($isActive) aria-current="true" @endif
                @class([
                    'rounded-md border px-3 py-1.5 text-sm font-medium transition-colors',
                    'border-primary bg-primary text-primary-foreground' => $isActive,
                    'border-border bg-background text-primary hover:border-primary/60' => ! $isActive,
                ])>{{ $label }}</a>
        @endforeach
    </nav>

    @if (session('status'))
        <p class="mt-4 rounded-lg border border-secondary/40 bg-secondary/10 px-4 py-3 text-sm text-primary" role="status">{{ session('status') }}</p>
    @endif

    <div class="ui-card mt-6 overflow-x-auto">
        <table class="w-full min-w-[44rem] text-left text-sm">
            <thead class="border-b border-border text-xs uppercase tracking-wide text-muted-foreground">
                <tr>
                    <th scope="col" class="px-4 py-3 font-medium">Logo</th>
                    <th scope="col" class="px-4 py-3 font-medium">Name</th>
                    <th scope="col" class="px-4 py-3 font-medium">Order</th>
                    <th scope="col" class="px-4 py-3 font-medium">Status</th>
                    <th scope="col" class="px-4 py-3 font-medium">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($partners as $partner)
                    <tr>
                        <td class="px-4 py-3">
                            @if ($partner->logo_path)
                                <img src="{{ Storage::disk('public')->url($partner->logo_path) }}" alt="" class="h-8 w-auto rounded border border-border object-cover">
                            @else
                                <span class="text-xs text-muted-foreground">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <a href="{{ route('admin.commercial-partners.show', $partner) }}" class="font-medium text-primary underline underline-offset-2 hover:text-secondary">{{ $partner->name }}</a>
                        </td>
                        <td class="px-4 py-3">{{ $partner->display_order }}</td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center rounded-md border px-2.5 py-0.5 text-xs font-semibold {{ $partner->is_active ? 'border-transparent bg-primary text-primary-foreground' : 'border-border bg-muted text-foreground' }}">
                                {{ $partner->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap gap-2">
                                <a href="{{ route('admin.commercial-partners.edit', $partner) }}" class="btn btn-sm border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground">Edit</a>

                                @if ($partner->is_active)
                                    <form method="POST" action="{{ route('admin.commercial-partners.deactivate', $partner) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-sm border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground">Deactivate</button>
                                    </form>
                                @else
                                    <form method="POST" action="{{ route('admin.commercial-partners.activate', $partner) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-brand">Activate</button>
                                    </form>
                                @endif

                                <form method="POST" action="{{ route('admin.commercial-partners.destroy', $partner) }}" onsubmit="return confirm('Delete this partner? This cannot be undone.');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm border border-[#CC001F]/40 text-[#CC001F] hover:bg-[#CC001F]/5">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-muted-foreground">No commercial partners found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">{{ $partners->links() }}</div>
</x-layouts.admin>
