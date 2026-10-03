<x-layouts.admin title="New product">
    <h1 class="font-display text-2xl font-bold text-primary md:text-3xl">New product</h1>
    <p class="mt-1 text-muted-foreground">Saved as inactive — activate it separately once you're ready.</p>

    <form method="POST" action="{{ route('admin.products.store') }}" enctype="multipart/form-data" class="ui-card mt-6 space-y-5 p-6 md:p-8">
        @csrf

        @include('admin.products._form', ['categories' => $categories])

        <div class="flex gap-3">
            <button type="submit" class="btn btn-lg btn-brand">Save product</button>
            <a href="{{ route('admin.products.index') }}" class="btn btn-lg border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground">Cancel</a>
        </div>
    </form>
</x-layouts.admin>
