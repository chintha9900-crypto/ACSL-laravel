@use('App\Models\JobListing')
@use('Illuminate\Support\Facades\Storage')
{{--
    Shared by create.blade.php and edit.blade.php. Status is deliberately not
    a field: saving never publishes or unpublishes a job. Publishing is its
    own action, which also requires a valid application URL and a deadline of
    today or later.
--}}
@php($job ??= null)
@if ($errors->any())
    <div class="mb-6 w-full rounded-lg border border-[#CC001F]/50 bg-[#CC001F]/5 px-4 py-3 text-sm text-[#CC001F]" role="alert">
        {{ $errors->first('job') ?: 'Please correct the highlighted fields and try again.' }}
    </div>
@endif

<div class="space-y-5">
    <x-form.input name="title" label="Job title" :value="$job?->title" maxlength="255" />

    <x-form.input name="company_name" label="Company / employer name" :value="$job?->company_name" maxlength="255" />

    <div class="space-y-1.5">
        <label for="company_logo" class="block text-sm font-medium leading-none">Company logo</label>

        @if ($job?->company_logo)
            <img src="{{ Storage::disk('public')->url($job->company_logo) }}" alt="Current logo" class="mb-2 h-16 w-auto rounded-md border border-border bg-white object-contain p-1">
        @endif

        <input id="company_logo" name="company_logo" type="file" accept=".jpg,.jpeg,.png,image/jpeg,image/png"
            class="min-w-0 max-w-full cursor-pointer text-sm text-muted-foreground file:mr-3 file:h-8 file:cursor-pointer file:rounded-md file:border file:border-input file:bg-background file:px-3 file:text-xs file:font-medium file:text-foreground file:shadow-sm hover:file:bg-accent focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring">
        <p class="text-xs text-muted-foreground">Optional. JPG or PNG, up to {{ round(config('uploads.job_logo.max_kb') / 1024, 1) }}MB.{{ $job?->company_logo ? ' Leave empty to keep the current logo.' : '' }}</p>
        @error('company_logo')
            <p class="text-xs text-[#CC001F]">{{ $message }}</p>
        @enderror

        @if ($job?->company_logo)
            <label for="remove_company_logo" class="mt-2 flex items-center gap-2 text-sm">
                <input id="remove_company_logo" name="remove_company_logo" type="checkbox" value="1" @checked(old('remove_company_logo')) class="h-4 w-4 rounded border-input">
                Remove current logo
            </label>
            <p class="text-xs text-muted-foreground">If you also choose a new logo above, the new logo replaces the current one instead.</p>
            @error('remove_company_logo')
                <p class="text-xs text-[#CC001F]">{{ $message }}</p>
            @enderror
        @endif
    </div>

    <div class="grid gap-5 md:grid-cols-3">
        @foreach ([
            'category' => ['Job category', JobListing::CATEGORIES, $job?->category],
            'employment_type' => ['Employment type', JobListing::EMPLOYMENT_TYPES, $job?->employment_type],
            'location_type' => ['Location classification', JobListing::LOCATION_TYPES, $job?->location_type],
        ] as $selectName => [$selectLabel, $options, $current])
            @php($selected = old($selectName, $current))
            <div class="space-y-1.5">
                <label for="{{ $selectName }}" class="block text-sm font-medium leading-none">{{ $selectLabel }} <span class="text-[#CC001F]" aria-hidden="true">*</span></label>
                <select id="{{ $selectName }}" name="{{ $selectName }}" required @error($selectName) aria-invalid="true" aria-describedby="{{ $selectName }}-error" @enderror class="field-control h-9">
                    <option value="">Select…</option>
                    @foreach ($options as $value => $optionLabel)
                        <option value="{{ $value }}" @selected($selected === $value)>{{ $optionLabel }}</option>
                    @endforeach
                </select>
                @error($selectName)
                    <p id="{{ $selectName }}-error" class="text-xs text-destructive">{{ $message }}</p>
                @enderror
            </div>
        @endforeach
    </div>
    <p class="-mt-3 text-xs text-muted-foreground">Choose Local or Overseas yourself; it is not worked out from the country.</p>

    <div class="grid gap-5 md:grid-cols-2">
        <x-form.input name="location" label="Location" :value="$job?->location" maxlength="255" />
        <x-form.input name="country" label="Country" :value="$job?->country" maxlength="100" />
    </div>

    <x-form.input name="short_description" label="Short description" type="textarea" rows="3" :value="$job?->short_description" maxlength="500"
        hint="Shown in the job list. Up to 500 characters." />

    @include('admin.partials.rich-text-editor', ['field' => 'description', 'fieldLabel' => 'Full job description', 'fieldRequired' => true, 'content' => $job?->description ?? ''])
    @include('admin.partials.rich-text-editor', ['field' => 'responsibilities', 'fieldLabel' => 'Responsibilities', 'fieldRequired' => false, 'content' => $job?->responsibilities ?? ''])
    @include('admin.partials.rich-text-editor', ['field' => 'qualifications', 'fieldLabel' => 'Required qualifications', 'fieldRequired' => false, 'content' => $job?->qualifications ?? ''])
    @include('admin.partials.rich-text-editor', ['field' => 'experience', 'fieldLabel' => 'Required experience', 'fieldRequired' => false, 'content' => $job?->experience ?? ''])
    @include('admin.partials.rich-text-editor', ['field' => 'additional_requirements', 'fieldLabel' => 'Additional requirements', 'fieldRequired' => false, 'content' => $job?->additional_requirements ?? ''])

    <x-form.input name="salary" label="Salary" :required="false" :value="$job?->salary" maxlength="255" hint="Optional. Shown exactly as entered." />

    <div class="grid gap-5 md:grid-cols-2">
        <x-form.input name="date_posted" label="Date posted" type="date" :required="false" :value="$job?->date_posted?->toDateString()"
            hint="Optional. Set to the publishing date if left empty." />
        <x-form.input name="application_deadline" label="Visible until the end of this date (Asia/Colombo time)" type="date" :value="$job?->application_deadline?->toDateString()"
            hint="The job stays visible throughout this date and expires after midnight, Asia/Colombo time. To publish, it must be today or later." />
    </div>

    <x-form.input name="application_url" label="External application URL" type="url" :required="false" :value="$job?->application_url" maxlength="2048"
        placeholder="https://"
        hint="The employer's own vacancy or application page (http:// or https://). Optional for a draft; required to publish." />
</div>
