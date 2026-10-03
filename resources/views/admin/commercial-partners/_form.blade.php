@use('Illuminate\Support\Facades\Storage')
{{--
    Shared by create.blade.php and edit.blade.php — mirrors
    admin/csr/_form.blade.php. `is_active` is deliberately not a field
    here — activation is its own workflow action on the index page (the
    same separation News/Events/CSR keep for publish/unpublish), not a
    value this form can silently change. No slug: CommercialPartner has
    none.
--}}
@if ($errors->any())
    <div class="mb-6 w-full rounded-lg border border-[#CC001F]/50 bg-[#CC001F]/5 px-4 py-3 text-sm text-[#CC001F]" role="alert">
        Please correct the highlighted fields and try again.
    </div>
@endif

<div class="space-y-5">
    <x-form.input name="name" label="Name" :value="old('name', $partner->name ?? '')" maxlength="255" />

    <x-form.input name="description" label="Description" type="textarea" rows="4" :required="false" :value="old('description', $partner->description ?? '')" />

    <x-form.input name="url" label="Website URL" :required="false" :value="old('url', $partner->url ?? '')" maxlength="255"
        hint="Must be a full URL, e.g. https://example.com." />

    <x-form.input name="display_order" label="Display order" type="number" :required="false" :value="old('display_order', $partner->display_order ?? 0)" min="0"
        hint="Lower numbers are listed first." />

    <div class="space-y-1.5">
        <label for="logo" class="block text-sm font-medium leading-none">Logo</label>

        @if (! empty($partner) && $partner->logo_path)
            <img src="{{ Storage::disk('public')->url($partner->logo_path) }}" alt="" class="mb-2 h-32 w-auto rounded-lg border border-border object-cover">
        @endif

        <input id="logo" name="logo" type="file" accept=".jpg,.jpeg,.png,image/jpeg,image/png"
            class="min-w-0 max-w-full cursor-pointer text-sm text-muted-foreground file:mr-3 file:h-8 file:cursor-pointer file:rounded-md file:border file:border-input file:bg-background file:px-3 file:text-xs file:font-medium file:text-foreground file:shadow-sm hover:file:bg-accent focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring">
        <p class="text-xs text-muted-foreground">JPG or PNG, up to {{ round(config('uploads.commercial_partner_logo.max_kb') / 1024, 1) }}MB. Leave empty to keep the current logo.</p>
        @error('logo')
            <p class="text-xs text-[#CC001F]">{{ $message }}</p>
        @enderror
    </div>
</div>
