{{--
    Become a Member — visual redesign only, matching /membership/benefits'
    look (red/dark-gray/gray/white, no blue/gradients). All fields, category
    logic, validation and the upload/JS progressive-enhancement below are
    exactly as before; only markup/classes changed, plus one small, deliberate
    addition: a mandatory declaration checkbox (see the `declaration` rule
    added to StoreMembershipApplicationRequest — the only backend touch this
    redesign needed, since an HTML-only "required" checkbox is not actually
    enforced).
--}}
@use('App\Models\MembershipCategory')

<x-layouts.public title="Become a Member">
    <x-public.hero>
        <x-slot:badge>
            <svg class="mr-1 h-3 w-3" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" x2="19" y1="8" y2="14"/><line x1="22" x2="16" y1="11" y2="11"/></svg>
            Become a Member
        </x-slot:badge>
        <x-slot:lead>
            Choose your membership type below and complete the application. Our team will review and get back to you.
        </x-slot:lead>
        Join the <span class="text-[#CC001F]">community.</span>
    </x-public.hero>

    <section class="container mx-auto max-w-5xl px-4 py-16 md:py-20 lg:px-8">
        <div class="space-y-8">
            <div class="ui-card border-l-4 border-[#CC001F] p-6 md:p-8">
                <h2 class="font-display text-2xl font-bold text-primary">Before you apply</h2>
                <ul class="mt-3 list-disc space-y-1.5 pl-5 text-sm text-muted-foreground">
                    <li>Membership is open to people who genuinely work, study or participate in the aviation field.</li>
                    <li>You must upload aviation eligibility proof: documents that show your connection to aviation.</li>
                    <li>Your application will be reviewed by ACI.</li>
                    <li>Submitting an application does not create a member account.</li>
                    <li>Approval does not immediately activate membership. Approval and activation are separate steps.</li>
                </ul>
            </div>

            @if ($categories->isEmpty())
                <p class="rounded-lg border border-border bg-muted px-4 py-3 text-sm text-foreground" role="status">
                    Applications are not open at the moment. Please check back soon.
                </p>
            @else
                @if ($selected)
                    {{-- The Benefits page's existing `?category=` mechanism (unchanged) —
                         this just makes the pre-selected category clearly visible. --}}
                    <p class="rounded-lg border border-[#CC001F]/30 bg-[#CC001F]/5 px-4 py-3 text-sm text-[#4D4D4D]" role="status">
                        You're applying for <strong>{{ $selected->name }}</strong> membership. You can change this below.
                    </p>
                @endif

                <h2 class="font-display text-2xl font-bold text-primary">Apply for membership</h2>

                <form id="application-form" method="POST" action="{{ route('membership.apply.store') }}" enctype="multipart/form-data" class="space-y-8">
                    @csrf

                    <fieldset>
                        <legend class="mb-3 font-display text-lg font-bold text-primary">1. Choose your membership category</legend>

                        @php
                            $categoryIcons = [
                                MembershipCategory::CODE_STUDENT => '<path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/>',
                                MembershipCategory::CODE_PROFESSIONAL => '<rect width="20" height="14" x="2" y="7" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>',
                                MembershipCategory::CODE_VETERAN => '<circle cx="12" cy="8" r="6"/><path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11"/>',
                            ];
                        @endphp

                        <div class="grid gap-3 md:grid-cols-3">
                            @foreach ($categories as $category)
                                <label class="cursor-pointer">
                                    <input
                                        type="radio"
                                        name="category"
                                        value="{{ $category->code }}"
                                        @checked($selected?->is($category))
                                        required
                                        class="peer sr-only"
                                    >
                                    <span class="flex flex-col items-center gap-2 rounded-lg border border-border bg-background px-4 py-5 text-center transition-colors hover:border-[#CC001F]/60 peer-checked:border-[#CC001F] peer-checked:bg-[#CC001F] peer-checked:text-white peer-checked:shadow-elegant peer-focus-visible:ring-2 peer-focus-visible:ring-ring peer-focus-visible:ring-offset-2">
                                        <svg class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $categoryIcons[$category->code] ?? $categoryIcons[MembershipCategory::CODE_PROFESSIONAL] !!}</svg>
                                        <span class="font-display text-sm font-semibold md:text-base">{{ $category->name }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>

                        @error('category')
                            <p class="mt-2 text-xs text-[#CC001F]">{{ $message }}</p>
                        @enderror
                    </fieldset>

                    <div class="ui-card p-6 md:p-8">
                        <h2 class="font-display text-lg font-bold text-primary">2. Your details</h2>
                        <p class="mt-1 text-sm text-muted-foreground">
                            All fields are required.
                        </p>

                        @if ($errors->any())
                            <div class="mt-6 w-full rounded-lg border border-[#CC001F]/50 bg-[#CC001F]/5 px-4 py-3 text-sm text-[#CC001F]" role="alert">
                                Please correct the highlighted fields and try again.
                            </div>
                        @endif

                        <div class="mt-6 space-y-5">
                            <x-form.input name="full_name" label="Full Name" autocomplete="name" maxlength="160" />

                            <div class="grid gap-4 md:grid-cols-2">
                                <x-form.input name="email" label="Email" type="email" autocomplete="email" maxlength="255" />

                                {{--
                                    Country-code + number widget. The `mobile` field/validation/
                                    storage are completely unchanged — this only changes how its
                                    value is *entered*. The visible select/number inputs carry no
                                    `name` and are never submitted directly; JS combines them into
                                    the hidden `mobile` field, and only gives it `name="mobile"`
                                    once it has actually run (a <noscript> fallback keeps the plain
                                    single field working exactly as before when JS is unavailable).
                                --}}
                                <div class="space-y-1.5">
                                    <label class="block text-sm font-medium leading-none">
                                        Mobile <span class="text-[#CC001F]" aria-hidden="true">*</span>
                                    </label>

                                    <noscript>
                                        <input type="tel" name="mobile" required maxlength="40" value="{{ old('mobile') }}" placeholder="+94 77 123 4567" class="field-control" @if ($errors->has('mobile')) aria-invalid="true" @endif>
                                    </noscript>

                                    <div data-mobile-widget class="flex gap-2">
                                        <select data-mobile-country class="field-control w-32 shrink-0 sm:w-40" aria-label="Country code">
                                            @foreach ($mobileCountries as $country)
                                                <option value="{{ $country['code'] }}" @selected($country['code'] === '+94')>{{ $country['flag'] }} {{ $country['name'] }} ({{ $country['code'] }})</option>
                                            @endforeach
                                        </select>
                                        <input type="tel" data-mobile-number inputmode="tel" placeholder="77 123 4567" class="field-control min-w-0 flex-1" aria-label="Mobile number" aria-describedby="mobile-hint" @if ($errors->has('mobile')) aria-invalid="true" @endif>
                                        <input type="hidden" data-mobile-combined value="{{ old('mobile') }}">
                                    </div>

                                    <p id="mobile-hint" class="text-xs text-muted-foreground">Select your country code, then enter your number.</p>

                                    @error('mobile')
                                        <p class="text-xs text-[#CC001F]">{{ $message }}</p>
                                    @enderror
                                </div>
                            </div>

                            <x-form.input name="address" label="Address" type="textarea" rows="2" maxlength="400" autocomplete="street-address" />
                        </div>
                    </div>

                    <div class="ui-card p-6 md:p-8">
                        <h2 class="font-display text-lg font-bold text-primary">3. Aviation details</h2>

                        <div class="mt-6 space-y-5">
                            <div class="grid gap-4 md:grid-cols-2">
                                <x-form.input name="aviation_role" label="Course Name / Occupation / Position Held" label-key="role" maxlength="160" />
                                <x-form.input name="aviation_organisation" label="Training Institute / Employer / Organisation" label-key="organisation" maxlength="200" />
                            </div>

                            <div data-category-section="{{ MembershipCategory::CODE_STUDENT }}" class="grid gap-4 md:grid-cols-2">
                                <noscript><p class="text-xs text-muted-foreground md:col-span-2">The next two fields are for students only.</p></noscript>
                                <x-form.input name="study_start_date" label="Course Start Date" type="date" :required="false" data-required-when-visible />
                                <x-form.input name="expected_completion_date" label="Expected Course Completion Date" type="date" :required="false" data-required-when-visible />
                            </div>

                            <div data-category-section="{{ MembershipCategory::CODE_VETERAN }}" class="space-y-5">
                                <noscript><p class="text-xs text-muted-foreground">The next two fields are for veterans only.</p></noscript>
                                <x-form.input name="previous_employers" label="Previous Employer(s)" type="textarea" rows="2" maxlength="2000" :required="false" data-required-when-visible />
                                <div class="grid gap-4 md:grid-cols-2">
                                    <x-form.input name="years_experience" label="Years of Experience" type="number" min="0" max="80" inputmode="numeric" :required="false" data-required-when-visible />
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="ui-card p-6 md:p-8">
                        <h2 class="font-display text-lg font-bold text-primary">4. Aviation eligibility proof</h2>
                        <p class="mt-1 text-sm text-muted-foreground">Required for every category — documents that show your connection to aviation.</p>

                        <div class="mt-4 space-y-1.5">
                            <label for="proof_documents" class="block text-sm font-medium leading-none">Upload Proof</label>

                            <label for="proof_documents" class="flex cursor-pointer flex-col items-center gap-2 rounded-lg border-2 border-dashed border-border bg-muted/40 p-6 text-center transition-colors hover:border-[#CC001F]/50 hover:bg-[#CC001F]/5">
                                <span class="grid h-11 w-11 place-items-center rounded-full bg-[#4D4D4D] text-white">
                                    <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12"/><path d="m7 8 5-5 5 5"/><path d="M5 21h14a2 2 0 0 0 2-2v-5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v5a2 2 0 0 0 2 2Z"/></svg>
                                </span>
                                <span class="text-sm font-medium text-primary">Click to attach supporting documents</span>
                                <span id="proof_documents-hint" class="text-xs text-muted-foreground">
                                    Accepted: PDF, JPG or PNG. Max {{ round($proof['max_kb'] / 1024, 1) }}MB per file, up to {{ $proof['max_files'] }} files.
                                </span>

                                <input
                                    id="proof_documents"
                                    name="proof_documents[]"
                                    type="file"
                                    multiple
                                    required
                                    accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                                    aria-describedby="proof_documents-hint @if ($errors->has('proof_documents') || $errors->has('proof_documents.*')) proof_documents-error @endif"
                                    @if ($errors->has('proof_documents') || $errors->has('proof_documents.*')) aria-invalid="true" @endif
                                    class="sr-only"
                                >
                            </label>

                            <p data-file-list class="text-xs text-muted-foreground" aria-live="polite"></p>

                            @foreach (collect($errors->get('proof_documents'))->merge(collect($errors->get('proof_documents.*'))->flatten())->unique() as $message)
                                <p id="{{ $loop->first ? 'proof_documents-error' : 'proof_documents-error-'.$loop->index }}" class="text-xs text-[#CC001F]">{{ $message }}</p>
                            @endforeach
                        </div>
                    </div>

                    <div class="ui-card p-6 md:p-8">
                        <h2 class="font-display text-lg font-bold text-primary">5. Declaration</h2>

                        <label class="mt-4 flex cursor-pointer items-start gap-3 rounded-lg border border-border bg-muted/40 p-4 text-sm text-foreground @error('declaration') border-[#CC001F]/50 bg-[#CC001F]/5 @enderror">
                            <input type="checkbox" name="declaration" value="1" required class="mt-0.5 h-4 w-4 shrink-0 accent-[#CC001F]" @checked(old('declaration'))>
                            <span>
                                I confirm that the details provided in this application are true, and that I have read and understood ACI's
                                <a href="{{ route('rules') }}" target="_blank" rel="noopener" class="font-semibold text-[#CC001F] hover:underline">Club Rules and Policies</a>.
                            </span>
                        </label>

                        @error('declaration')
                            <p class="mt-2 text-xs text-[#CC001F]">{{ $message }}</p>
                        @enderror
                    </div>

                    <button type="submit" class="btn btn-lg btn-brand w-full">Submit Application</button>
                </form>

                {{-- Progressive enhancement (no library): show only the selected category's fields.
                     Without JavaScript every field stays visible and the server applies the same rules. --}}
                <script>
                    (function () {
                        var form = document.getElementById('application-form');
                        var labels = {
                            S: { role: 'Course Name', organisation: 'Training Institute' },
                            P: { role: 'Occupation', organisation: 'Employer / Organisation' },
                            V: { role: 'Position Held', organisation: 'Most Recent Aviation Employer' }
                        };

                        function apply() {
                            var checked = form.querySelector('input[name="category"]:checked');
                            var code = checked ? checked.value : null;

                            form.querySelectorAll('[data-category-section]').forEach(function (section) {
                                var active = section.getAttribute('data-category-section') === code;
                                section.hidden = !active;
                                section.querySelectorAll('[data-required-when-visible]').forEach(function (field) {
                                    field.required = active;
                                });
                            });

                            if (code && labels[code]) {
                                Object.keys(labels[code]).forEach(function (key) {
                                    var el = form.querySelector('[data-label-for="' + key + '"]');
                                    if (el) { el.textContent = labels[code][key]; }
                                });
                            }
                        }

                        form.addEventListener('change', function (event) {
                            if (event.target.name === 'category') { apply(); }
                        });
                        apply();

                        // Purely cosmetic: reflect the browser's own selected-files list
                        // back to the applicant (the styled dropzone hides the native
                        // input's built-in indicator). No validation happens here.
                        var proof = document.getElementById('proof_documents');
                        var fileList = form.querySelector('[data-file-list]');
                        if (proof && fileList) {
                            proof.addEventListener('change', function () {
                                var names = Array.prototype.map.call(proof.files, function (file) { return file.name; });
                                fileList.textContent = names.length ? names.length + ' file(s) selected: ' + names.join(', ') : '';
                            });
                        }

                        // Mobile country-code widget. The combined field only gets
                        // name="mobile" here, once JS has actually run — with JS off,
                        // the <noscript> field above is the only "mobile" input
                        // submitted, exactly as before this widget existed.
                        var mobileCountry = form.querySelector('[data-mobile-country]');
                        var mobileNumber = form.querySelector('[data-mobile-number]');
                        var mobileCombined = form.querySelector('[data-mobile-combined]');

                        if (mobileCountry && mobileNumber && mobileCombined) {
                            mobileCombined.name = 'mobile';

                            var previous = mobileCombined.value.trim();
                            if (previous !== '') {
                                // Re-split a previous value (e.g. after a validation error
                                // redisplay) by the longest matching known dial code.
                                var codes = Array.prototype.map.call(mobileCountry.options, function (o) { return o.value; });
                                codes.sort(function (a, b) { return b.length - a.length; });
                                var matched = codes.find(function (code) { return previous.indexOf(code) === 0; });

                                if (matched) {
                                    mobileCountry.value = matched;
                                    mobileNumber.value = previous.slice(matched.length).trim();
                                } else {
                                    mobileNumber.value = previous;
                                }
                            }

                            function combineMobile() {
                                var code = mobileCountry.value;
                                var number = mobileNumber.value.trim();
                                mobileCombined.value = number === '' ? '' : code + ' ' + number;
                            }

                            mobileCountry.addEventListener('change', combineMobile);
                            mobileNumber.addEventListener('input', combineMobile);
                            form.addEventListener('submit', combineMobile);
                            combineMobile();
                        }
                    })();
                </script>
            @endif
        </div>
    </section>
</x-layouts.public>
