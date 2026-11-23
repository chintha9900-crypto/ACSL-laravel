@use('App\Models\EducationArticle')
<x-layouts.admin title="Education Management">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <h1 class="font-display text-2xl font-bold text-primary md:text-3xl">Education Management</h1>
        @can('create', EducationArticle::class)
            <a href="{{ route('admin.education.create') }}" class="btn btn-lg btn-brand">Add article</a>
        @endcan
    </div>

    <nav class="mt-4 flex flex-wrap gap-2" aria-label="Filter by status">
        @foreach (['' => 'All', ...EducationArticle::STATUSES] as $value => $label)
            @php($active = ($status ?? '') === (string) $value)
            <a href="{{ route('admin.education.index', array_filter(['status' => $value])) }}"
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

    @if ($errors->any())
        <p class="mt-4 rounded-lg border border-[#CC001F]/50 bg-[#CC001F]/5 px-4 py-3 text-sm text-[#CC001F]" role="alert">{{ $errors->first() }}</p>
    @endif

    <p class="mt-4 text-sm text-muted-foreground">Articles appear on the public Education page in their column, lowest display order first.</p>

    <div class="ui-card mt-4 overflow-x-auto">
        <table class="w-full min-w-[48rem] text-left text-sm">
            <thead class="border-b border-border text-xs uppercase tracking-wide text-muted-foreground">
                <tr>
                    <th scope="col" class="px-4 py-3 font-medium">Title</th>
                    <th scope="col" class="px-4 py-3 font-medium">Column</th>
                    <th scope="col" class="px-4 py-3 font-medium">Order</th>
                    <th scope="col" class="px-4 py-3 font-medium">Status</th>
                    <th scope="col" class="px-4 py-3 font-medium">Updated</th>
                    <th scope="col" class="px-4 py-3 font-medium">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($articles as $article)
                    <tr>
                        <td class="px-4 py-3 font-medium text-primary">{{ $article->title }}</td>
                        <td class="px-4 py-3 whitespace-nowrap">{{ $article->columnLabel() }}</td>
                        <td class="px-4 py-3">{{ $article->sort_order }}</td>
                        <td class="px-4 py-3">
                            <span @class([
                                'inline-flex items-center rounded-md border px-2.5 py-0.5 text-xs font-semibold',
                                'border-transparent bg-primary text-primary-foreground' => $article->isPublished(),
                                'border-border bg-muted text-foreground' => ! $article->isPublished(),
                            ])>{{ $article->statusLabel() }}</span>
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap">{{ $article->updated_at->format('j M Y') }}</td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap gap-2">
                                @can('view', $article)
                                    <a href="{{ route('admin.education.show', $article) }}" class="btn btn-sm border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground">Preview</a>
                                @endcan
                                @can('update', $article)
                                    <a href="{{ route('admin.education.edit', $article) }}" class="btn btn-sm border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground">Edit</a>
                                @endcan
                                @can('publish', $article)
                                    @if ($article->isPublished())
                                        <form method="POST" action="{{ route('admin.education.unpublish', $article) }}">
                                            @csrf
                                            <button type="submit" class="btn btn-sm border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground">Unpublish</button>
                                        </form>
                                    @else
                                        <form method="POST" action="{{ route('admin.education.publish', $article) }}">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-brand">Publish</button>
                                        </form>
                                    @endif
                                @endcan
                                @can('delete', $article)
                                    <form method="POST" action="{{ route('admin.education.destroy', $article) }}" onsubmit="return confirm('Delete this article and its images? This cannot be undone.');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm border border-[#CC001F]/40 text-[#CC001F] hover:bg-[#CC001F]/5">Delete</button>
                                    </form>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center text-muted-foreground">No articles found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">{{ $articles->links() }}</div>
</x-layouts.admin>
