@use('Illuminate\Support\Facades\Storage')
{{--
    Shared by create.blade.php and edit.blade.php. Status/publish is
    deliberately not a field here — publishing is its own workflow action on
    the index page (docs/frontend/05 §1: "workflow buttons, no arbitrary
    status jumps"), not a value this form can silently change.
--}}
@if ($errors->any())
    <div class="mb-6 w-full rounded-lg border border-[#CC001F]/50 bg-[#CC001F]/5 px-4 py-3 text-sm text-[#CC001F]" role="alert">
        Please correct the highlighted fields and try again.
    </div>
@endif

<div class="space-y-5">
    <x-form.input name="title" label="Title" :value="old('title', $post->title ?? '')" data-title-field maxlength="255" />

    <x-form.input name="slug" label="Slug" :value="old('slug', $post->slug ?? '')" data-slug-field maxlength="255"
        hint="Used in the article's URL. Letters, numbers, dashes and underscores only." />

    <div class="space-y-1.5">
        <label for="blog_category_id" class="block text-sm font-medium leading-none">Category</label>
        <select id="blog_category_id" name="blog_category_id" class="field-control">
            <option value="">No category</option>
            @foreach ($categories as $category)
                <option value="{{ $category->id }}" @selected((int) old('blog_category_id', $post->blog_category_id ?? '') === $category->id)>{{ $category->name }}</option>
            @endforeach
        </select>
        @error('blog_category_id')
            <p class="text-xs text-[#CC001F]">{{ $message }}</p>
        @enderror
    </div>

    <x-form.input name="excerpt" label="Excerpt" type="textarea" rows="3" :required="false" :value="old('excerpt', $post->excerpt ?? '')" maxlength="2000"
        hint="A short summary shown on the blog listing page." />

    <x-form.input name="content" label="Content" type="textarea" rows="12" :value="old('content', $post->content ?? '')"
        hint="Basic HTML is allowed (paragraphs, headings, lists, bold/italic, links) — anything else is stripped when you save." />

    <div class="space-y-1.5">
        <label for="featured_image" class="block text-sm font-medium leading-none">Featured image</label>

        @if (! empty($post) && $post->featured_image_path)
            <img src="{{ Storage::disk('public')->url($post->featured_image_path) }}" alt="{{ $post->featured_image_alt ?? '' }}" class="mb-2 h-32 w-auto rounded-lg border border-border object-cover">
        @endif

        <input id="featured_image" name="featured_image" type="file" accept=".jpg,.jpeg,.png,image/jpeg,image/png"
            class="min-w-0 max-w-full cursor-pointer text-sm text-muted-foreground file:mr-3 file:h-8 file:cursor-pointer file:rounded-md file:border file:border-input file:bg-background file:px-3 file:text-xs file:font-medium file:text-foreground file:shadow-sm hover:file:bg-accent focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring">
        <p class="text-xs text-muted-foreground">JPG or PNG, up to {{ round(config('uploads.blog_featured_image.max_kb') / 1024, 1) }}MB. Leave empty to keep the current image.</p>
        @error('featured_image')
            <p class="text-xs text-[#CC001F]">{{ $message }}</p>
        @enderror
    </div>

    <x-form.input name="featured_image_alt" label="Featured image alt text" :required="false" :value="old('featured_image_alt', $post->featured_image_alt ?? '')" maxlength="255"
        hint="Describes the image for screen readers. Leave empty if there is no featured image." />
</div>

{{-- Progressive enhancement (no library): suggest a slug from the title,
     but only until the admin edits the slug field themselves. --}}
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
