@use('App\Models\EducationArticle')
@use('Illuminate\Support\Facades\Storage')
{{--
    Shared by create.blade.php and edit.blade.php. Status is not a field:
    saving never publishes; Publish/Unpublish are separate actions.
--}}
@php($article ??= null)
@if ($errors->any())
    <div class="mb-6 w-full rounded-lg border border-[#CC001F]/50 bg-[#CC001F]/5 px-4 py-3 text-sm text-[#CC001F]" role="alert">
        {{ $errors->first('article') ?: 'Please correct the highlighted fields and try again.' }}
    </div>
@endif

<div class="space-y-5">
    <x-form.input name="title" label="Title" :value="$article?->title" maxlength="255" />

    <x-form.input name="short_description" label="Short description" type="textarea" rows="3" :value="$article?->short_description" maxlength="500"
        hint="Shown on the topic card on the Education page. Up to 500 characters." />

    <div class="grid gap-5 md:grid-cols-2">
        <fieldset class="space-y-1.5">
            <legend class="block text-sm font-medium leading-none">Display position <span class="text-[#CC001F]" aria-hidden="true">*</span></legend>
            @php($selectedColumn = old('display_column', $article?->display_column ?? EducationArticle::COLUMN_LEFT))
            <div class="flex flex-wrap gap-4 pt-1">
                @foreach (EducationArticle::COLUMNS as $value => $label)
                    <label class="flex items-center gap-2 text-sm">
                        <input type="radio" name="display_column" value="{{ $value }}" @checked($selectedColumn === $value) required class="h-4 w-4">
                        {{ $label }}
                    </label>
                @endforeach
            </div>
            <p class="text-xs text-muted-foreground">Left: the main list of topics. Right: the sidebar cards.</p>
            @error('display_column')
                <p class="text-xs text-destructive">{{ $message }}</p>
            @enderror
        </fieldset>

        <x-form.input name="sort_order" label="Display order" type="number" :value="$article?->sort_order ?? 0" min="0" max="65535"
            hint="Lower numbers appear first within the column." />
    </div>

    <div class="space-y-1.5">
        <label for="featured_image" class="block text-sm font-medium leading-none">Featured image</label>
        @if ($article?->featured_image_path)
            <img src="{{ Storage::disk('public')->url($article->featured_image_path) }}" alt="{{ $article->featured_image_alt ?? '' }}" class="mb-2 h-32 w-auto rounded-lg border border-border object-cover">
        @endif
        <input id="featured_image" name="featured_image" type="file" accept=".jpg,.jpeg,.png,image/jpeg,image/png"
            class="min-w-0 max-w-full cursor-pointer text-sm text-muted-foreground file:mr-3 file:h-8 file:cursor-pointer file:rounded-md file:border file:border-input file:bg-background file:px-3 file:text-xs file:font-medium file:text-foreground file:shadow-sm hover:file:bg-accent focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring">
        <p class="text-xs text-muted-foreground">Optional. JPG or PNG, up to {{ round(config('uploads.education_image.max_kb') / 1024, 1) }}MB.{{ $article?->featured_image_path ? ' Leave empty to keep the current image.' : '' }}</p>
        @error('featured_image')
            <p class="text-xs text-[#CC001F]">{{ $message }}</p>
        @enderror

        @if ($article?->featured_image_path)
            <label for="remove_featured_image" class="mt-2 flex items-center gap-2 text-sm">
                <input id="remove_featured_image" name="remove_featured_image" type="checkbox" value="1" @checked(old('remove_featured_image')) class="h-4 w-4 rounded border-input">
                Remove current image
            </label>
            <p class="text-xs text-muted-foreground">If you also choose a new image above, the new image replaces the current one instead.</p>
        @endif
    </div>

    <x-form.input name="featured_image_alt" label="Featured image description (alt text)" :required="false" :value="$article?->featured_image_alt" maxlength="255"
        hint="Describes the image for people who cannot see it." />

    @include('admin.partials.rich-text-editor', [
        'field' => 'content',
        'fieldLabel' => 'Article content',
        'content' => $article?->content ?? '',
        'imageUploadUrl' => route('admin.education.images.store'),
        'imageUrlPrefix' => EducationArticle::inlineImageUrlPrefix(),
        'imageMaxKb' => (int) config('uploads.education_inline_image.max_kb'),
    ])

    <fieldset class="space-y-3 rounded-lg border border-border p-4">
        <legend class="px-1 text-sm font-medium">Card buttons (optional)</legend>
        <p class="text-xs text-muted-foreground">
            Shown on right-column cards: one button appears as a red text link, two appear as a filled and an outlined button.
            Links must be a page on this site (starting with /) or a full https:// address.
        </p>
        <div class="grid gap-4 md:grid-cols-2">
            <x-form.input name="button_label" label="Button label" :required="false" :value="$article?->button_label" maxlength="100" />
            <x-form.input name="button_url" label="Button link" :required="false" :value="$article?->button_url" maxlength="2048" placeholder="/membership/apply" />
            <x-form.input name="secondary_button_label" label="Second button label" :required="false" :value="$article?->secondary_button_label" maxlength="100" />
            <x-form.input name="secondary_button_url" label="Second button link" :required="false" :value="$article?->secondary_button_url" maxlength="2048" placeholder="/contact" />
        </div>
    </fieldset>
</div>
