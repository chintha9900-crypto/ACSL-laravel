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
--}}
@php
    $rteField = $field ?? 'content';
    $rteLabel = $fieldLabel ?? 'Content';
    $rteRequired = $fieldRequired ?? true;
    $rteEditorId = str_replace(['[', ']', '.'], '_', $rteField).'-editor';
@endphp
<div class="space-y-1.5" data-rte-root="{{ $rteField }}">
    <x-form.input :name="$rteField" :label="$rteLabel" :required="$rteRequired" type="textarea" rows="12" :value="HtmlSanitizer::forDisplay((string) old($rteField, $content ?? ''))"
        hint="Formatting: headings, bold, italic, underline, lists, quotes, links and text alignment. Anything else is removed when you save." />

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
    })(@json($rteField));
</script>
