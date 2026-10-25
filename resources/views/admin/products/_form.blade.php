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

    @php($imageConfig = config('uploads.product_image'))
    @php($existingImages = ($product->images ?? collect())->sortBy('display_order')->values())
    @php($primaryImageId = (int) old('primary_image', $existingImages->first()?->id))
    <fieldset class="space-y-3" data-gallery-field data-max-bytes="{{ $imageConfig['max_kb'] * 1024 }}" data-max-label="{{ round($imageConfig['max_kb'] / 1024, 1) }} MB"
        data-max-files="{{ $imageConfig['max_files'] }}" data-existing-count="{{ $existingImages->count() }}">
        <legend class="text-sm font-medium leading-none">Product images</legend>
        <p class="text-xs text-muted-foreground">Up to {{ $imageConfig['max_files'] }} images per product, including the main image. The main image is shown in the shop listing; the others appear in the product page gallery. To replace an image, tick Remove on it and add the new one in the same save.</p>
        @if ($existingImages->count() > $imageConfig['max_files'])
            <p class="rounded-md border border-[#CC001F]/40 bg-[#CC001F]/5 px-3 py-2 text-xs text-[#CC001F]" role="status" data-over-limit>
                This product has {{ $existingImages->count() }} images, more than the current limit of {{ $imageConfig['max_files'] }}. They are all kept; remove {{ $existingImages->count() - $imageConfig['max_files'] }} before adding any new images.
            </p>
        @endif

        @if ($existingImages->isNotEmpty())
            <ul class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" aria-label="Current images">
                @foreach ($existingImages as $existing)
                    <li class="rounded-lg border border-border p-2 text-xs" data-existing-image>
                        <img src="{{ $product->imageUrl($existing) }}" alt="{{ $existing->alt_text ?: ($product->name ?? 'Product').' image '.$loop->iteration }}" class="aspect-square w-full rounded-md object-cover">
                        <label class="mt-2 flex items-center gap-1.5">
                            <input type="radio" name="primary_image" value="{{ $existing->id }}" @checked($primaryImageId === $existing->id)>
                            Main image
                        </label>
                        <label class="mt-1 flex items-center gap-1.5">
                            Position
                            <input type="number" name="image_order[{{ $existing->id }}]" value="{{ old('image_order.'.$existing->id, $loop->index + 1) }}" min="0" max="999" class="field-control h-7 w-16 px-2 text-xs">
                        </label>
                        <label class="mt-1 flex items-center gap-1.5 text-[#CC001F]">
                            <input type="checkbox" name="remove_images[]" value="{{ $existing->id }}" @checked(in_array((string) $existing->id, (array) old('remove_images', []), true))>
                            Remove
                        </label>
                    </li>
                @endforeach
            </ul>
        @endif

        <div class="space-y-1.5">
            <label for="images" class="block text-sm font-medium leading-none">Add images</label>
            <input id="images" name="images[]" type="file" multiple accept=".jpg,.jpeg,.png,image/jpeg,image/png" data-gallery-input
                @error('images') aria-invalid="true" @enderror
                class="min-w-0 max-w-full cursor-pointer text-sm text-muted-foreground file:mr-3 file:h-8 file:cursor-pointer file:rounded-md file:border file:border-input file:bg-background file:px-3 file:text-xs file:font-medium file:text-foreground file:shadow-sm hover:file:bg-accent focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring">
            <p class="text-xs text-muted-foreground">JPG or PNG, up to {{ round($imageConfig['max_kb'] / 1024, 1) }}MB each. You can select several at once (up to {{ max(0, $imageConfig['max_files'] - $existingImages->count()) }} more for this product); they are added after the current images. Existing images are kept unless you tick Remove.</p>
            <p data-gallery-warning hidden role="alert" class="text-xs text-[#CC001F]"></p>
            <ul data-gallery-preview hidden class="grid grid-cols-3 gap-2 sm:grid-cols-6" aria-label="Selected images"></ul>
            @foreach ($errors->get('images*') as $messages)
                @foreach ($messages as $message)
                    <p class="text-xs text-[#CC001F]">{{ $message }}</p>
                @endforeach
            @endforeach
            @error('primary_image')
                <p class="text-xs text-[#CC001F]">{{ $message }}</p>
            @enderror
        </div>
    </fieldset>
</div>

{{-- Previews of newly selected images; type and size are re-checked on the server. --}}
<script>
    (function () {
        var field = document.querySelector('[data-gallery-field]');
        if (!field) { return; }
        var input = field.querySelector('[data-gallery-input]');
        var preview = field.querySelector('[data-gallery-preview]');
        var warning = field.querySelector('[data-gallery-warning]');
        var maxBytes = parseInt(field.getAttribute('data-max-bytes'), 10);
        var urls = [];

        input.addEventListener('change', function () {
            urls.forEach(function (url) { URL.revokeObjectURL(url); });
            urls = [];
            preview.innerHTML = '';
            warning.hidden = true;
            var problems = [];

            Array.prototype.forEach.call(input.files || [], function (file) {
                if (['image/jpeg', 'image/png'].indexOf(file.type) === -1) {
                    problems.push(file.name + ' is not a JPG or PNG image.');
                    return;
                }
                if (file.size > maxBytes) {
                    problems.push(file.name + ' is larger than ' + field.getAttribute('data-max-label') + '.');
                    return;
                }
                var url = URL.createObjectURL(file);
                urls.push(url);
                var item = document.createElement('li');
                var image = document.createElement('img');
                image.src = url;
                image.alt = 'Preview of ' + file.name;
                image.className = 'aspect-square w-full rounded-md border border-border object-cover';
                item.appendChild(image);
                preview.appendChild(item);
            });

            preview.hidden = urls.length === 0;

            // Same total the server enforces: kept images + new ones <= limit.
            var maxFiles = parseInt(field.getAttribute('data-max-files'), 10);
            var kept = parseInt(field.getAttribute('data-existing-count'), 10)
                - field.querySelectorAll('input[name="remove_images[]"]:checked').length;
            var selected = (input.files || []).length;
            if (selected > 0 && kept + selected > maxFiles) {
                problems.push('A product can have at most ' + maxFiles + ' images including the main image; this would make ' + (kept + selected) + '. Tick Remove on an image or choose fewer files.');
            }

            if (problems.length) {
                warning.textContent = problems.join(' ') + ' The server will reject this when you save.';
                warning.hidden = false;
            }
        });

        // Re-check when Remove ticks change after files were chosen.
        field.querySelectorAll('input[name="remove_images[]"]').forEach(function (box) {
            box.addEventListener('change', function () { input.dispatchEvent(new Event('change')); });
        });
    })();
</script>

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
