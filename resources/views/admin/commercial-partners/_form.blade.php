@use('Illuminate\Support\Facades\Storage')
{{--
    Shared by create.blade.php and edit.blade.php. Only three fields: logo,
    partner name and company website. The public page shows logos only.
    Existing description / display order values are kept unchanged (they are
    simply no longer edited here). `is_active` is its own workflow action on
    the index page, not a value this form can silently change.
--}}
@if ($errors->any())
    <div class="mb-6 w-full rounded-lg border border-[#CC001F]/50 bg-[#CC001F]/5 px-4 py-3 text-sm text-[#CC001F]" role="alert">
        Please correct the highlighted fields and try again.
    </div>
@endif

@php($hasLogo = ! empty($partner) && $partner->logo_path)
@php($logoMaxKb = (int) config('uploads.commercial_partner_logo.max_kb'))
<div class="space-y-5">
    <div class="space-y-1.5" data-logo-field data-max-bytes="{{ $logoMaxKb * 1024 }}" data-max-label="{{ round($logoMaxKb / 1024, 1) }} MB">
        <label for="logo" class="block text-sm font-medium leading-none">
            Logo image
            @unless ($hasLogo)
                <span class="text-[#CC001F]" aria-hidden="true">*</span>
            @endunless
        </label>

        <div class="flex h-28 w-48 max-w-full items-center justify-center rounded-lg border border-border bg-background p-3">
            <img data-logo-preview @if ($hasLogo) src="{{ Storage::disk('public')->url($partner->logo_path) }}" @else hidden @endif
                alt="{{ $hasLogo ? 'Current logo of '.$partner->name : 'Selected logo preview' }}" class="max-h-full max-w-full object-contain">
            <span data-logo-empty @if ($hasLogo) hidden @endif class="text-xs text-muted-foreground">No logo yet</span>
        </div>

        <input id="logo" name="logo" type="file" accept=".jpg,.jpeg,.png,image/jpeg,image/png" @required(! $hasLogo) data-logo-input
            @error('logo') aria-invalid="true" @enderror aria-describedby="logo-hint"
            class="min-w-0 max-w-full cursor-pointer text-sm text-muted-foreground file:mr-3 file:h-8 file:cursor-pointer file:rounded-md file:border file:border-input file:bg-background file:px-3 file:text-xs file:font-medium file:text-foreground file:shadow-sm hover:file:bg-accent focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring">
        <p id="logo-hint" class="text-xs text-muted-foreground">JPG or PNG, up to {{ round($logoMaxKb / 1024, 1) }}MB.@if ($hasLogo) Leave empty to keep the current logo.@endif</p>
        <p data-logo-warning hidden role="alert" class="text-xs text-[#CC001F]"></p>
        @error('logo')
            <p class="text-xs text-[#CC001F]">{{ $message }}</p>
        @enderror
    </div>

    <x-form.input name="name" label="Partner name" :value="old('name', $partner->name ?? '')" maxlength="255"
        hint="Not shown on the public page; used as the logo's description for screen readers." />

    <x-form.input name="url" label="Company website URL" type="url" :value="old('url', $partner->url ?? '')" maxlength="255" inputmode="url"
        hint="A full address, e.g. https://example.com. The logo links here." />
</div>

{{-- Preview the selected logo before saving; type/size are re-checked on the server. --}}
<script>
    (function () {
        var field = document.querySelector('[data-logo-field]');
        if (!field) { return; }
        var input = field.querySelector('[data-logo-input]');
        var preview = field.querySelector('[data-logo-preview]');
        var empty = field.querySelector('[data-logo-empty]');
        var warning = field.querySelector('[data-logo-warning]');
        var original = preview.getAttribute('src');
        var maxBytes = parseInt(field.getAttribute('data-max-bytes'), 10);
        var objectUrl = null;

        function restore() {
            if (objectUrl) { URL.revokeObjectURL(objectUrl); objectUrl = null; }
            if (original) { preview.src = original; preview.hidden = false; empty.hidden = true; }
            else { preview.removeAttribute('src'); preview.hidden = true; empty.hidden = false; }
        }

        input.addEventListener('change', function () {
            warning.hidden = true;
            restore();
            var file = input.files && input.files[0];
            if (!file) { return; }
            if (['image/jpeg', 'image/png'].indexOf(file.type) === -1 || file.size > maxBytes) {
                input.value = '';
                warning.textContent = 'Choose a JPG or PNG image up to ' + field.getAttribute('data-max-label') + '.';
                warning.hidden = false;
                return;
            }
            objectUrl = URL.createObjectURL(file);
            preview.src = objectUrl;
            preview.hidden = false;
            empty.hidden = true;
        });
    })();
</script>
