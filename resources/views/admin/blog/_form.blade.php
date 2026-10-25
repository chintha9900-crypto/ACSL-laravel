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

    {{-- Rich-text editor (no library): a toolbar over an editable area that
         writes into the `content` textarea. Without JavaScript the textarea is
         used directly. Content is allow-list sanitised on the server either way. --}}
    <div class="space-y-1.5" data-rte-root>
        <x-form.input name="content" label="Content" type="textarea" rows="12" :value="old('content', $post->content ?? '')"
            hint="Formatting: headings, bold, italic, underline, lists, quotes, links and text alignment. Anything else is removed when you save." />

        <div data-rte-toolbar hidden role="toolbar" aria-label="Formatting" aria-controls="content-editor" class="flex flex-wrap gap-1 rounded-t-md border border-b-0 border-input bg-muted/40 p-1.5">
            @foreach ([
                ['formatBlock', 'h2', 'H2', 'Heading 2'],
                ['formatBlock', 'h3', 'H3', 'Heading 3'],
                ['formatBlock', 'p', '¶', 'Paragraph'],
                ['bold', null, 'B', 'Bold'],
                ['italic', null, 'I', 'Italic'],
                ['underline', null, 'U', 'Underline'],
                ['insertUnorderedList', null, '• List', 'Bulleted list'],
                ['insertOrderedList', null, '1. List', 'Numbered list'],
                ['formatBlock', 'blockquote', '“ ”', 'Quote'],
                ['createLink', null, 'Link', 'Insert link'],
                ['unlink', null, 'Unlink', 'Remove link'],
                ['justifyLeft', null, 'Left', 'Align left'],
                ['justifyCenter', null, 'Centre', 'Align centre'],
                ['justifyRight', null, 'Right', 'Align right'],
                ['justifyFull', null, 'Justify', 'Justify'],
            ] as [$command, $value, $label, $title])
                <button type="button" data-rte-command="{{ $command }}" @if ($value) data-rte-value="{{ $value }}" @endif title="{{ $title }}" aria-label="{{ $title }}"
                    class="rounded border border-transparent px-2 py-1 text-xs font-semibold text-foreground hover:border-input hover:bg-background focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring">{{ $label }}</button>
            @endforeach
        </div>
        <div id="content-editor" data-rte-editor hidden contenteditable="true" role="textbox" aria-multiline="true" aria-label="Content"
            class="blog-content min-h-[16rem] w-full rounded-b-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"></div>
    </div>

    <x-form.input name="tags" label="Tags" :required="false" :value="old('tags', isset($post) ? $post->tags->pluck('name')->implode(', ') : '')" maxlength="500"
        hint="Separate tags with commas, e.g. Pilots, Training. Up to 15." />

    @if (! empty($post))
        <div class="space-y-1.5">
            <input type="hidden" name="is_featured" value="0">
            <label class="flex items-start gap-2 text-sm">
                <input type="checkbox" name="is_featured" value="1" class="mt-0.5" @checked(old('is_featured', $post->is_featured))>
                <span>Featured post — show this published post at the top of the blog. Only one post can be featured; selecting this replaces the current one.</span>
            </label>
            @error('is_featured')
                <p class="text-xs text-[#CC001F]">{{ $message }}</p>
            @enderror
        </div>
    @endif

    @php($imageMaxKb = (int) config('uploads.blog_featured_image.max_kb'))
    <div class="space-y-1.5" data-image-field data-max-bytes="{{ $imageMaxKb * 1024 }}" data-max-label="{{ round($imageMaxKb / 1024, 1) }} MB">
        <label for="featured_image" class="block text-sm font-medium leading-none">Featured image</label>

        @if (! empty($post) && $post->featured_image_path)
            <img src="{{ Storage::disk('public')->url($post->featured_image_path) }}" alt="{{ $post->featured_image_alt ?? '' }}" data-current-image class="mb-2 h-32 w-auto rounded-lg border border-border object-cover">
        @endif

        <input id="featured_image" name="featured_image" type="file" accept=".jpg,.jpeg,.png,image/jpeg,image/png" data-image-input
            class="min-w-0 max-w-full cursor-pointer text-sm text-muted-foreground file:mr-3 file:h-8 file:cursor-pointer file:rounded-md file:border file:border-input file:bg-background file:px-3 file:text-xs file:font-medium file:text-foreground file:shadow-sm hover:file:bg-accent focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring">
        <p class="text-xs text-muted-foreground">JPG or PNG, up to {{ round($imageMaxKb / 1024, 1) }}MB. Leave empty to keep the current image.</p>
        <p data-image-warning hidden role="alert" class="text-xs text-[#CC001F]"></p>
        <div data-image-preview hidden class="flex items-end gap-3">
            <img alt="Selected image preview" class="h-32 w-auto rounded-lg border border-border object-cover">
            <button type="button" data-image-remove class="btn btn-sm border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground">Remove selected image</button>
        </div>
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

    // Rich-text editor: the editable area mirrors the `content` textarea.
    (function () {
        var root = document.querySelector('[data-rte-root]');
        if (!root || typeof document.execCommand !== 'function') { return; }
        var textarea = root.querySelector('textarea[name="content"]');
        var toolbar = root.querySelector('[data-rte-toolbar]');
        var editor = root.querySelector('[data-rte-editor]');
        if (!textarea || !toolbar || !editor) { return; }

        editor.innerHTML = textarea.value;
        textarea.hidden = true;
        textarea.removeAttribute('required');
        toolbar.hidden = false;
        editor.hidden = false;

        // Browsers create a list from a paragraph as <p><ul>…</ul></p>, which is
        // invalid HTML: the server would split it and leave empty paragraphs.
        // Move such lists out of their paragraph and drop the empty remainder.
        function normalize() {
            editor.querySelectorAll('p').forEach(function (p) {
                var lists = Array.prototype.filter.call(p.children, function (child) { return child.tagName === 'UL' || child.tagName === 'OL'; });
                if (!lists.length) { return; }
                var anchor = p;
                lists.forEach(function (list) { anchor.parentNode.insertBefore(list, anchor.nextSibling); anchor = list; });
                if (p.textContent.trim() === '' && !p.querySelector('img')) { p.remove(); }
            });
        }

        function sync() { textarea.value = editor.innerHTML.trim() === '<br>' ? '' : editor.innerHTML; }

        toolbar.addEventListener('mousedown', function (event) {
            if (event.target.closest('[data-rte-command]')) { event.preventDefault(); } // keep the selection
        });
        toolbar.addEventListener('click', function (event) {
            var button = event.target.closest('[data-rte-command]');
            if (!button) { return; }
            var command = button.getAttribute('data-rte-command');
            var value = button.getAttribute('data-rte-value');
            editor.focus();
            if (command === 'createLink') {
                value = window.prompt('Link address (https://…)', 'https://');
                if (!value || !/^(https?:\/\/|\/|#)/i.test(value.trim())) { return; }
                value = value.trim();
            }
            // Alignment is stored as text-align; everything else as plain tags.
            document.execCommand('styleWithCSS', false, command.indexOf('justify') === 0);
            document.execCommand(command, false, command === 'formatBlock' ? '<' + value + '>' : value);
            normalize();
            sync();
        });
        editor.addEventListener('input', sync);
        textarea.form.addEventListener('submit', function () { normalize(); sync(); });
    })();

    // Featured image: immediate type/size check, preview, and removal.
    (function () {
        var field = document.querySelector('[data-image-field]');
        if (!field) { return; }
        var input = field.querySelector('[data-image-input]');
        var warning = field.querySelector('[data-image-warning]');
        var preview = field.querySelector('[data-image-preview]');
        var image = preview.querySelector('img');
        var remove = field.querySelector('[data-image-remove]');
        var maxBytes = parseInt(field.getAttribute('data-max-bytes'), 10);
        var maxLabel = field.getAttribute('data-max-label');
        var objectUrl = null;

        function clearPreview() {
            if (objectUrl) { URL.revokeObjectURL(objectUrl); objectUrl = null; }
            image.removeAttribute('src');
            preview.hidden = true;
        }

        function reject(message) {
            input.value = '';
            clearPreview();
            warning.textContent = message;
            warning.hidden = false;
        }

        input.addEventListener('change', function () {
            warning.hidden = true;
            clearPreview();
            var file = input.files && input.files[0];
            if (!file) { return; }
            if (['image/jpeg', 'image/png'].indexOf(file.type) === -1) {
                reject('This file is not a JPG or PNG image. Please choose a JPG or PNG file.');
                return;
            }
            if (file.size > maxBytes) {
                reject('This image is ' + (file.size / 1048576).toFixed(1) + ' MB, which is larger than the ' + maxLabel + ' limit. Please choose a smaller image.');
                return;
            }
            objectUrl = URL.createObjectURL(file);
            image.src = objectUrl;
            preview.hidden = false;
        });

        remove.addEventListener('click', function () {
            input.value = '';
            warning.hidden = true;
            clearPreview();
            input.focus();
        });
    })();
</script>
