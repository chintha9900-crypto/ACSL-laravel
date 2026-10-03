@use('Illuminate\Support\Facades\Storage')
<x-layouts.admin :title="$partner->name">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <h1 class="font-display text-2xl font-bold text-primary md:text-3xl">{{ $partner->name }}</h1>
        <div class="flex gap-2">
            <a href="{{ route('admin.commercial-partners.edit', $partner) }}" class="btn btn-sm border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground">Edit</a>
            <a href="{{ route('admin.commercial-partners.index') }}" class="btn btn-sm border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground">Back to partners</a>
        </div>
    </div>

    <div class="ui-card mt-6 grid gap-6 p-6 md:grid-cols-2 md:p-8">
        <div>
            @if ($partner->logo_path)
                <img src="{{ Storage::disk('public')->url($partner->logo_path) }}" alt="" class="w-full rounded-lg border border-border object-cover">
            @else
                <div class="grid aspect-video w-full place-items-center rounded-lg border border-border bg-muted text-sm text-muted-foreground">
                    No logo uploaded
                </div>
            @endif
        </div>

        <dl class="space-y-4 text-sm">
            <div>
                <dt class="font-medium text-muted-foreground">Status</dt>
                <dd>
                    <span class="inline-flex items-center rounded-md border px-2.5 py-0.5 text-xs font-semibold {{ $partner->is_active ? 'border-transparent bg-primary text-primary-foreground' : 'border-border bg-muted text-foreground' }}">
                        {{ $partner->is_active ? 'Active' : 'Inactive' }}
                    </span>
                </dd>
            </div>
            <div>
                <dt class="font-medium text-muted-foreground">Display order</dt>
                <dd>{{ $partner->display_order }}</dd>
            </div>
            @if ($partner->url)
                <div>
                    <dt class="font-medium text-muted-foreground">Website</dt>
                    <dd><a href="{{ $partner->url }}" class="text-primary underline underline-offset-2 hover:text-secondary" target="_blank" rel="noopener noreferrer">{{ $partner->url }}</a></dd>
                </div>
            @endif
            @if ($partner->description)
                <div>
                    <dt class="font-medium text-muted-foreground">Description</dt>
                    <dd class="whitespace-pre-line">{{ $partner->description }}</dd>
                </div>
            @endif
        </dl>
    </div>
</x-layouts.admin>
