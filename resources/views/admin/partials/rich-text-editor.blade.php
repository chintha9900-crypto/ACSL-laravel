@use('App\Support\HtmlSanitizer')
{{--
    The Blog form's rich-text editor (no library), shared by the News, Events
    and CSR forms: a toolbar over an editable area that writes into the
    `content` textarea. Without JavaScript the textarea is used directly.
    Content is allow-list sanitised on the server either way. Older plain-text
    content opens as paragraphs, keeping its line breaks.

    Several editors can share one form; each binds only to its own field.
    Callers that pass only `content` get the original markup unchanged
    (field "content", label "Content", editor id "content-editor").

    @param  string|null  $content  The record's saved value, if any.
    @param  string  $field  The textarea name (default "content").
    @param  string  $fieldLabel  The visible label (default "Content").
    @param  bool  $fieldRequired  Whether the field is required (default true).
    @param  string|null  $imageUploadUrl  Opt-in (Education only): adds an "Image" button that uploads to
                                         this URL and inserts the returned image. Null (the default) renders
                                         nothing extra, so every other caller's output is unchanged.
    @param  string|null  $imageUrlPrefix  With $imageUploadUrl: the only image path prefix the cleaner keeps.
    @param  int|null  $imageMaxKb  With $imageUploadUrl: the upload size limit, checked before uploading.
--}}
@php
    $rteField = $field ?? 'content';
    $rteLabel = $fieldLabel ?? 'Content';
    $rteRequired = $fieldRequired ?? true;
    $rteEditorId = str_replace(['[', ']', '.'], '_', $rteField).'-editor';
    $rteImageUploadUrl = $imageUploadUrl ?? null;
    $rteImageUrlPrefix = $rteImageUploadUrl ? ($imageUrlPrefix ?? null) : null;
@endphp
<div class="space-y-1.5" data-rte-root="{{ $rteField }}">
    <x-form.input :name="$rteField" :label="$rteLabel" :required="$rteRequired" type="textarea" rows="12" :value="HtmlSanitizer::forDisplay((string) old($rteField, $content ?? ''), $rteImageUrlPrefix)"
        :hint="$rteImageUploadUrl
            ? 'Formatting: headings, bold, italic, underline, lists, quotes, links, text alignment and images (JPG or PNG, added with the Image button; double-click an image to change its description, or select it and press Delete to remove it). Anything else is removed when you save.'
            : 'Formatting: headings, bold, italic, underline, lists, quotes, links and text alignment. Anything else is removed when you save.'" />

    <div data-rte-toolbar hidden role="toolbar" aria-label="Formatting" aria-controls="{{ $rteEditorId }}" class="flex flex-wrap gap-1 rounded-t-md border border-b-0 border-input bg-muted/40 p-1.5">
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
        @if ($rteImageUploadUrl)
            <button type="button" data-rte-image data-rte-image-url="{{ $rteImageUploadUrl }}" data-rte-image-max-bytes="{{ (int) ($imageMaxKb ?? 3072) * 1024 }}" data-rte-csrf="{{ csrf_token() }}" title="Insert image" aria-label="Insert image"
                class="rounded border border-transparent px-2 py-1 text-xs font-semibold text-foreground hover:border-input hover:bg-background focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:opacity-50">Image</button>
            <input type="file" data-rte-image-input accept=".jpg,.jpeg,.png,image/jpeg,image/png" hidden tabindex="-1" aria-hidden="true">
        @endif
    </div>
    <div id="{{ $rteEditorId }}" data-rte-editor hidden contenteditable="true" role="textbox" aria-multiline="true" aria-label="{{ $rteLabel }}"
        class="blog-content min-h-[16rem] w-full rounded-b-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"></div>
</div>

<script>
    // Rich-text editor: the editable area mirrors its own field's textarea.
    (function (field) {
        var root = Array.prototype.find.call(document.querySelectorAll('[data-rte-root]'), function (el) { return el.getAttribute('data-rte-root') === field; });
        if (!root || typeof document.execCommand !== 'function') { return; }
        var textarea = Array.prototype.find.call(root.querySelectorAll('textarea'), function (el) { return el.name === field; });
        var toolbar = root.querySelector('[data-rte-toolbar]');
        var editor = root.querySelector('[data-rte-editor]');
        if (!textarea || !toolbar || !editor) { return; }

        // Enter must create paragraphs: the server unwraps the browser's default
        // <div> lines, which would run them together.
        document.execCommand('defaultParagraphSeparator', false, 'p');
        editor.innerHTML = textarea.value || '<p><br></p>';
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

        function sync() { textarea.value = /^(<p>)?<br>(<\/p>)?$/.test(editor.innerHTML.trim()) ? '' : editor.innerHTML; }

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
@if ($rteImageUploadUrl)

        // Image button (Education only): upload the chosen file, ask for alt
        // text, and insert it as its own paragraph after the current block.
        // The element is built with the DOM API (no HTML strings); the server
        // keeps only images from its own upload folder anyway.
        var imageButton = toolbar.querySelector('[data-rte-image]');
        var imageInput = toolbar.querySelector('[data-rte-image-input]');
        var savedRange = null;

        imageButton.addEventListener('click', function () {
            var selection = window.getSelection();
            savedRange = selection.rangeCount && editor.contains(selection.anchorNode) ? selection.getRangeAt(0).cloneRange() : null;
            imageInput.value = '';
            imageInput.click();
        });

        imageInput.addEventListener('change', function () {
            var file = imageInput.files[0];
            if (!file) { return; }
            if (['image/jpeg', 'image/png'].indexOf(file.type) === -1) { window.alert('Please choose a JPG or PNG image.'); return; }
            if (file.size > Number(imageButton.getAttribute('data-rte-image-max-bytes'))) { window.alert('This image is too large. Please choose a smaller image.'); return; }

            var data = new FormData();
            data.append('image', file);
            imageButton.disabled = true;
            imageButton.textContent = 'Uploading…';

            fetch(imageButton.getAttribute('data-rte-image-url'), {
                method: 'POST', body: data, credentials: 'same-origin',
                headers: { 'X-CSRF-TOKEN': imageButton.getAttribute('data-rte-csrf'), 'Accept': 'application/json' },
            })
                .then(function (response) {
                    return response.json().catch(function () { return {}; }).then(function (json) {
                        if (!response.ok || typeof json.url !== 'string') {
                            throw new Error((json.errors && json.errors.image && json.errors.image[0]) || 'The image could not be uploaded.');
                        }
                        return json;
                    });
                })
                .then(function (json) {
                    var image = document.createElement('img');
                    image.src = json.url;
                    image.alt = (window.prompt('Describe this image for people who cannot see it (alt text):', '') || '').trim();
                    var paragraph = document.createElement('p');
                    paragraph.appendChild(image);

                    var block = savedRange ? savedRange.startContainer : null;
                    while (block && block.parentNode !== editor) { block = block.parentNode; }
                    if (block) { editor.insertBefore(paragraph, block.nextSibling); } else { editor.appendChild(paragraph); }
                    sync();
                })
                .catch(function (error) { window.alert(error.message); })
                .finally(function () { imageButton.disabled = false; imageButton.textContent = 'Image'; });
        });

        editor.addEventListener('dblclick', function (event) {
            if (event.target.tagName !== 'IMG') { return; }
            var alt = window.prompt('Describe this image for people who cannot see it (alt text):', event.target.alt);
            if (alt === null) { return; }
            event.target.alt = alt.trim();
            sync();
        });
@endif
    })(@json($rteField));
</script>
