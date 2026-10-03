@use('Illuminate\Support\Facades\Storage')
{{--
    Shared by create.blade.php and edit.blade.php. `is_active` is
    deliberately not a field here — activating/deactivating is its own
    workflow action on the index page, not a value this form can silently
    change (same reasoning as blog/_form.blade.php's status field).
--}}
@if ($errors->any())
    <div class="mb-6 w-full rounded-lg border border-[#CC001F]/50 bg-[#CC001F]/5 px-4 py-3 text-sm text-[#CC001F]" role="alert">
        Please correct the highlighted fields and try again.
    </div>
@endif

<div class="space-y-5">
    <x-form.input name="name" label="Product name" :value="old('name', $product->name ?? '')" data-title-field maxlength="255" />

    <x-form.input name="slug" label="Slug" :value="old('slug', $product->slug ?? '')" data-slug-field maxlength="255"
        hint="Used in the product's URL. Letters, numbers, dashes and underscores only." />

    <x-form.input name="sku" label="SKU" :value="old('sku', $product->sku ?? '')" maxlength="64" hint="Must be unique across all products." />

    <div class="space-y-1.5">
        <label for="product_category_id" class="block text-sm font-medium leading-none">
            Category <span class="text-[#CC001F]" aria-hidden="true">*</span>
        </label>
        <select id="product_category_id" name="product_category_id" required class="field-control">
            <option value="">Select a category</option>
            @foreach ($categories as $category)
                <option value="{{ $category->id }}" @selected((int) old('product_category_id', $product->product_category_id ?? '') === $category->id)>{{ $category->name }}</option>
            @endforeach
        </select>
        @error('product_category_id')
            <p class="text-xs text-[#CC001F]">{{ $message }}</p>
        @enderror
    </div>

    <x-form.input name="description" label="Description" type="textarea" rows="4" :required="false" :value="old('description', $product->description ?? '')" />

    <x-form.input name="price" label="Price (LKR)" type="number" :value="old('price', $product->price ?? '')" step="0.01" min="0" />

    <x-form.input name="quantity" label="Stock quantity" type="number" :value="old('quantity', $product->inventory->quantity ?? 0)" min="0"
        hint="How many units are currently in stock." />

    <div class="space-y-1.5">
        <label for="access_type" class="block text-sm font-medium leading-none">
            Access type <span class="text-[#CC001F]" aria-hidden="true">*</span>
        </label>
        <select id="access_type" name="access_type" required class="field-control">
            @php($currentAccessType = old('access_type', $product->access_type ?? 'PUBLIC'))
            <option value="PUBLIC" @selected($currentAccessType === 'PUBLIC')>Public — anyone can view and purchase</option>
            <option value="MEMBER_ONLY" @selected($currentAccessType === 'MEMBER_ONLY')>Member only — authenticated active members only</option>
        </select>
        @error('access_type')
            <p class="text-xs text-[#CC001F]">{{ $message }}</p>
        @enderror
    </div>

    <div class="space-y-1.5">
        <label for="image" class="block text-sm font-medium leading-none">Product image</label>

        @php($existingImage = ($product->images ?? collect())->sortBy('display_order')->first())
        @if ($existingImage)
            <img src="{{ Storage::disk('public')->url($existingImage->image_path) }}" alt="{{ $existingImage->alt_text ?? '' }}" class="mb-2 h-32 w-auto rounded-lg border border-border object-cover">
        @endif

        <input id="image" name="image" type="file" accept=".jpg,.jpeg,.png,image/jpeg,image/png"
            class="min-w-0 max-w-full cursor-pointer text-sm text-muted-foreground file:mr-3 file:h-8 file:cursor-pointer file:rounded-md file:border file:border-input file:bg-background file:px-3 file:text-xs file:font-medium file:text-foreground file:shadow-sm hover:file:bg-accent focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring">
        <p class="text-xs text-muted-foreground">JPG or PNG, up to {{ round(config('uploads.product_image.max_kb') / 1024, 1) }}MB. Leave empty to keep the current image.</p>
        @error('image')
            <p class="text-xs text-[#CC001F]">{{ $message }}</p>
        @enderror
    </div>
</div>

{{-- Progressive enhancement (no library): suggest a slug from the name, but
     only until the admin edits the slug field themselves. --}}
<script>
    (function () {
        var title = document.querySelector('[data-title-field]');
        var slug = document.querySelector('[data-slug-field]');
        if (!title || !slug) { return; }

        var slugTouched = slug.value.trim() !== '';

        slug.addEventListener('input', function () { slugTouched = true; });

        title.addEventListener('input', function () {
            if (slugTouched) { return; }
            slug.value = title.value
                .toLowerCase()
                .trim()
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-+|-+$/g, '');
        });
    })();
</script>
