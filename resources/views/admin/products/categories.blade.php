<x-layouts.admin title="Product categories">
    <h1 class="font-display text-2xl font-bold text-primary md:text-3xl">Product categories</h1>
    <p class="mt-1 text-muted-foreground">A category with products assigned cannot be deleted until they are reassigned or removed.</p>

    <div class="ui-card mt-6 p-6 md:p-8">
        <h2 class="font-display text-lg font-bold text-primary">Add a category</h2>

        @if ($errors->any())
            <div class="mt-4 w-full rounded-lg border border-[#CC001F]/50 bg-[#CC001F]/5 px-4 py-3 text-sm text-[#CC001F]" role="alert">
                Please correct the highlighted fields and try again.
            </div>
        @endif

        <form method="POST" action="{{ route('admin.product-categories.store') }}" class="mt-4 grid gap-4 sm:grid-cols-2">
            @csrf
            <x-form.input name="name" label="Name" maxlength="150" data-title-field />
            <x-form.input name="slug" label="Slug" maxlength="150" data-slug-field hint="Letters, numbers, dashes and underscores only." />
            <div class="sm:col-span-2">
                <x-form.input name="description" label="Description" type="textarea" rows="2" :required="false" />
            </div>
            <div class="sm:col-span-2">
                <button type="submit" class="btn btn-lg btn-brand">Add category</button>
            </div>
        </form>
    </div>

    <div class="ui-card mt-6 overflow-x-auto">
        <table class="w-full min-w-[48rem] text-left text-sm">
            <thead class="border-b border-border text-xs uppercase tracking-wide text-muted-foreground">
                <tr>
                    <th scope="col" class="px-4 py-3 font-medium">Name</th>
                    <th scope="col" class="px-4 py-3 font-medium">Slug</th>
                    <th scope="col" class="px-4 py-3 font-medium">Products</th>
                    <th scope="col" class="px-4 py-3 font-medium">Status</th>
                    <th scope="col" class="px-4 py-3 font-medium">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($categories as $category)
                    <tr>
                        <td class="px-4 py-3">
                            <form method="POST" action="{{ route('admin.product-categories.update', $category) }}" class="flex flex-wrap items-center gap-2">
                                @csrf
                                @method('PATCH')
                                <input type="text" name="name" value="{{ old('name', $category->name) }}" maxlength="150" required class="field-control h-8 w-40">
                                <input type="text" name="slug" value="{{ old('slug', $category->slug) }}" maxlength="150" required class="field-control h-8 w-40">
                                <input type="text" name="description" value="{{ old('description', $category->description) }}" placeholder="Description" class="field-control h-8 w-48">
                                <button type="submit" class="btn btn-sm border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground">Save</button>
                            </form>
                        </td>
                        <td class="px-4 py-3 font-mono text-xs text-muted-foreground">{{ $category->slug }}</td>
                        <td class="px-4 py-3">{{ $category->products_count }}</td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center rounded-md border px-2.5 py-0.5 text-xs font-semibold {{ $category->is_active ? 'border-transparent bg-primary text-primary-foreground' : 'border-border bg-muted text-foreground' }}">
                                {{ $category->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap gap-2">
                                @if ($category->is_active)
                                    <form method="POST" action="{{ route('admin.product-categories.deactivate', $category) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-sm border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground">Deactivate</button>
                                    </form>
                                @else
                                    <form method="POST" action="{{ route('admin.product-categories.activate', $category) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-brand">Activate</button>
                                    </form>
                                @endif

                                <form method="POST" action="{{ route('admin.product-categories.destroy', $category) }}" onsubmit="return confirm('Delete this category? This cannot be undone.');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm border border-[#CC001F]/40 text-[#CC001F] hover:bg-[#CC001F]/5">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-muted-foreground">No categories yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <script>
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
