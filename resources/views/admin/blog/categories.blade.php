<x-layouts.admin title="Blog categories">
    <h1 class="font-display text-2xl font-bold text-primary md:text-3xl">Blog categories</h1>
    <p class="mt-1 text-muted-foreground">Deleting a category never deletes its posts — they simply become uncategorised.</p>

    @if (session('status'))
        <p class="mt-4 rounded-lg border border-secondary/40 bg-secondary/10 px-4 py-3 text-sm text-primary" role="status">{{ session('status') }}</p>
    @endif

    <div class="ui-card mt-6 p-6 md:p-8">
        <h2 class="font-display text-lg font-bold text-primary">Add a category</h2>

        @if ($errors->any())
            <div class="mt-4 w-full rounded-lg border border-[#CC001F]/50 bg-[#CC001F]/5 px-4 py-3 text-sm text-[#CC001F]" role="alert">
                Please correct the highlighted fields and try again.
            </div>
        @endif

        <form method="POST" action="{{ route('admin.blog-categories.store') }}" class="mt-4 grid gap-4 sm:grid-cols-2">
            @csrf
            <x-form.input name="name" label="Name" maxlength="100" data-title-field />
            <x-form.input name="slug" label="Slug" maxlength="100" data-slug-field hint="Letters, numbers, dashes and underscores only." />
            <div class="sm:col-span-2">
                <button type="submit" class="btn btn-lg btn-brand">Add category</button>
            </div>
        </form>
    </div>

    <div class="ui-card mt-6 overflow-x-auto">
        <table class="w-full min-w-[36rem] text-left text-sm">
            <thead class="border-b border-border text-xs uppercase tracking-wide text-muted-foreground">
                <tr>
                    <th scope="col" class="px-4 py-3 font-medium">Name</th>
                    <th scope="col" class="px-4 py-3 font-medium">Slug</th>
                    <th scope="col" class="px-4 py-3 font-medium">Posts</th>
                    <th scope="col" class="px-4 py-3 font-medium">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($categories as $category)
                    <tr>
                        <td class="px-4 py-3">
                            <form method="POST" action="{{ route('admin.blog-categories.update', $category) }}" class="flex flex-wrap items-center gap-2">
                                @csrf
                                @method('PATCH')
                                <input type="text" name="name" value="{{ old('name', $category->name) }}" maxlength="100" required class="field-control h-8 w-40">
                                <input type="text" name="slug" value="{{ old('slug', $category->slug) }}" maxlength="100" required class="field-control h-8 w-40">
                                <button type="submit" class="btn btn-sm border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground">Save</button>
                            </form>
                        </td>
                        <td class="px-4 py-3 font-mono text-xs text-muted-foreground">{{ $category->slug }}</td>
                        <td class="px-4 py-3">{{ $category->posts_count }}</td>
                        <td class="px-4 py-3">
                            <form method="POST" action="{{ route('admin.blog-categories.destroy', $category) }}" onsubmit="return confirm('Delete this category? Its posts will become uncategorised.');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm border border-[#CC001F]/40 text-[#CC001F] hover:bg-[#CC001F]/5">Delete</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-8 text-center text-muted-foreground">No categories yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <script>
        // Progressive enhancement, same pattern as the post form: suggest a
        // slug from the name until the admin edits the slug themselves.
        (function () {
            var name = document.querySelector('[data-title-field]');
            var slug = document.querySelector('[data-slug-field]');
            if (!name || !slug) { return; }

            var slugTouched = slug.value.trim() !== '';
            slug.addEventListener('input', function () { slugTouched = true; });
            name.addEventListener('input', function () {
                if (slugTouched) { return; }
                slug.value = name.value.toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
            });
        })();
    </script>
</x-layouts.admin>
