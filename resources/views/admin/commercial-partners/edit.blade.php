<x-layouts.admin title="Edit commercial partner">
    <h1 class="font-display text-2xl font-bold text-primary md:text-3xl">Edit commercial partner</h1>

    <form method="POST" action="{{ route('admin.commercial-partners.update', $partner) }}" enctype="multipart/form-data" class="ui-card mt-6 space-y-5 p-6 md:p-8">
        @csrf
        @method('PATCH')

        @include('admin.commercial-partners._form', ['partner' => $partner])

        <div class="flex gap-3">
            <button type="submit" class="btn btn-lg btn-brand">Save changes</button>
            <a href="{{ route('admin.commercial-partners.index') }}" class="btn btn-lg border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground">Cancel</a>
        </div>
    </form>
</x-layouts.admin>
