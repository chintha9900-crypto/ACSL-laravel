@use('Illuminate\Support\Facades\Storage')
{{--
    Shared by create.blade.php and edit.blade.php — mirrors
    admin/news/_form.blade.php. Status/publish is deliberately not a field
    here — publishing is its own workflow action on the index page, not a
    value this form can silently change. No excerpt field: CsrProject has
    none (unlike BlogPost/NewsItem/EventListing).
--}}
@if ($errors->any())
    <div class="mb-6 w-full rounded-lg border border-[#CC001F]/50 bg-[#CC001F]/5 px-4 py-3 text-sm text-[#CC001F]" role="alert">
        Please correct the highlighted fields and try again.
    </div>
@endif

<div class="space-y-5">
    <x-form.input name="title" label="Title" :value="old('title', $project->title ?? '')" data-title-field maxlength="255" />

    <x-form.input name="slug" label="Slug" :value="old('slug', $project->slug ?? '')" data-slug-field maxlength="255"
        hint="Used in the project's URL. Letters, numbers, dashes and underscores only." />

    <x-form.input name="content" label="Content" type="textarea" rows="12" :value="old('content', $project->content ?? '')"
        hint="Basic HTML is allowed (paragraphs, headings, lists, bold/italic, links) — anything else is stripped when you save." />

    <div class="space-y-1.5">
        <label for="image" class="block text-sm font-medium leading-none">Image</label>

        @if (! empty($project) && $project->image_path)
            <img src="{{ Storage::disk('public')->url($project->image_path) }}" alt="" class="mb-2 h-32 w-auto rounded-lg border border-border object-cover">
        @endif

        <input id="image" name="image" type="file" accept=".jpg,.jpeg,.png,image/jpeg,image/png"
            class="min-w-0 max-w-full cursor-pointer text-sm text-muted-foreground file:mr-3 file:h-8 file:cursor-pointer file:rounded-md file:border file:border-input file:bg-background file:px-3 file:text-xs file:font-medium file:text-foreground file:shadow-sm hover:file:bg-accent focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring">
        <p class="text-xs text-muted-foreground">JPG or PNG, up to {{ round(config('uploads.csr_image.max_kb') / 1024, 1) }}MB. Leave empty to keep the current image.</p>
        @error('image')
            <p class="text-xs text-[#CC001F]">{{ $message }}</p>
        @enderror
    </div>
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
