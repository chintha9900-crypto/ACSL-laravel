{{--
    Become a Member — visual redesign only, matching /membership/benefits'
    look (red/dark-gray/gray/white, no blue/gradients). All fields, category
    logic, validation and the upload/JS progressive-enhancement below are
    exactly as before; only markup/classes changed, plus one small, deliberate
    addition: a mandatory declaration checkbox (see the `declaration` rule
    added to StoreMembershipApplicationRequest — the only backend touch this
    redesign needed, since an HTML-only "required" checkbox is not actually
    enforced).

    Aviation Enthusiast and Corporate (approved Architecture/Database Design
    change, backend in StoreMembershipApplicationRequest) reuse this same
    form and the same field names — nothing new is invented here, only shown
    or hidden:
    - Enthusiast simply never sees the "Aviation eligibility proof" card
      (`data-category-section-hide-for`) — its Student/Professional/Veteran
      fields are unaffected, and Enthusiast uses the same "Your
      details"/"Aviation details" cards as Professional does.
    - Corporate needs a genuinely different 3-section layout (Company
      Details / Representative Details / Supporting Document), not just a
      relabelled individual form, so it gets its own
      `data-category-section="C"` cards with their own copies of the shared
      fields (full_name/email/mobile/address/aviation_role/
      aviation_organisation/proof_documents — identical backend field names,
      just a different, statically-labelled input on the page), plus the
      four Corporate-only fields. The individual-only cards are hidden *and
      have their inputs disabled* (`data-category-section-hide-for="C"`) so
      a hidden, stale duplicate never overwrites the visible one — disabled
      fields are excluded from the submitted form data.
      Corporate's own duplicated inputs are hand-written (not
      <x-form.input>), because that component derives its `id` from `name`
      and two inputs sharing one `name` would collide on `id` too.
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
                                MembershipCategory::CODE_ENTHUSIAST => '<path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.29 1.51 4.04 3 5.5l7 7Z"/>',
                                MembershipCategory::CODE_CORPORATE => '<rect x="4" y="2" width="16" height="20" rx="2"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01M16 6h.01M12 10h.01M16 10h.01M8 10h.01M12 14h.01M16 14h.01M8 14h.01"/>',
                            ];
                        @endphp

                        <div class="grid gap-3 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5">
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

                    {{-- Student / Professional / Veteran / Enthusiast: unchanged. --}}
                    <div data-category-section-hide-for="{{ MembershipCategory::CODE_CORPORATE }}" class="ui-card p-6 md:p-8">
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

                    <div data-category-section-hide-for="{{ MembershipCategory::CODE_CORPORATE }}" class="ui-card p-6 md:p-8">
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

                    {{-- Corporate — Section 1: Company Details. --}}
                    <div data-category-section="{{ MembershipCategory::CODE_CORPORATE }}" class="ui-card p-6 md:p-8">
                        <h2 class="font-display text-lg font-bold text-primary">2. Company Details</h2>
                        <p class="mt-1 text-sm text-muted-foreground">All fields are required unless marked optional.</p>

                        @if ($errors->any())
                            <div class="mt-6 w-full rounded-lg border border-[#CC001F]/50 bg-[#CC001F]/5 px-4 py-3 text-sm text-[#CC001F]" role="alert">
                                Please correct the highlighted fields and try again.
                            </div>
                        @endif

                        <div class="mt-6 space-y-5">
                            <div class="space-y-1.5">
                                <label for="company_name" class="block text-sm font-medium leading-none">Company Name <span class="text-[#CC001F]" aria-hidden="true">*</span></label>
                                <input id="company_name" name="company_name" type="text" value="{{ old('company_name') }}" maxlength="160" data-required-when-visible @if ($errors->has('company_name')) aria-invalid="true" @endif class="field-control">
                                @error('company_name')
                                    <p class="text-xs text-[#CC001F]">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="grid gap-4 md:grid-cols-2">
                                <div class="space-y-1.5">
                                    <label for="aviation_role_corporate" class="block text-sm font-medium leading-none">Company Type <span class="text-[#CC001F]" aria-hidden="true">*</span></label>
                                    <input id="aviation_role_corporate" name="aviation_role" type="text" value="{{ old('aviation_role') }}" maxlength="160" placeholder="e.g. Airline, Flying School, MRO" data-required-when-visible @if ($errors->has('aviation_role')) aria-invalid="true" @endif class="field-control">
                                    @error('aviation_role')
                                        <p class="text-xs text-[#CC001F]">{{ $message }}</p>
                                    @enderror
                                </div>

                                <div class="space-y-1.5">
                                    <label for="company_email" class="block text-sm font-medium leading-none">Company Email <span class="text-[#CC001F]" aria-hidden="true">*</span></label>
                                    <input id="company_email" name="company_email" type="email" value="{{ old('company_email') }}" maxlength="255" data-required-when-visible @if ($errors->has('company_email')) aria-invalid="true" @endif class="field-control">
                                    @error('company_email')
                                        <p class="text-xs text-[#CC001F]">{{ $message }}</p>
                                    @enderror
                                </div>
                            </div>

                            <div class="grid gap-4 md:grid-cols-2">
                                <div class="space-y-1.5">
                                    <label for="company_phone" class="block text-sm font-medium leading-none">Company Phone <span class="text-[#CC001F]" aria-hidden="true">*</span></label>
                                    <input id="company_phone" name="company_phone" type="tel" value="{{ old('company_phone') }}" maxlength="40" placeholder="+94 11 234 5678" data-required-when-visible @if ($errors->has('company_phone')) aria-invalid="true" @endif class="field-control">
                                    @error('company_phone')
                                        <p class="text-xs text-[#CC001F]">{{ $message }}</p>
                                    @enderror
                                </div>

                                <div class="space-y-1.5">
                                    <label for="company_website" class="block text-sm font-medium leading-none">Company Website</label>
                                    <input id="company_website" name="company_website" type="url" value="{{ old('company_website') }}" maxlength="255" placeholder="https://" @if ($errors->has('company_website')) aria-invalid="true" @endif class="field-control">
                                    @error('company_website')
                                        <p class="text-xs text-[#CC001F]">{{ $message }}</p>
                                    @enderror
                                </div>
                            </div>

                            <div class="space-y-1.5">
                                <label for="address_corporate" class="block text-sm font-medium leading-none">Company Address <span class="text-[#CC001F]" aria-hidden="true">*</span></label>
                                <textarea id="address_corporate" name="address" rows="2" maxlength="400" data-required-when-visible @if ($errors->has('address')) aria-invalid="true" @endif class="field-control">{{ old('address') }}</textarea>
                                @error('address')
                                    <p class="text-xs text-[#CC001F]">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>
                    </div>

                    {{-- Corporate — Section 2: Representative Details. --}}
                    <div data-category-section="{{ MembershipCategory::CODE_CORPORATE }}" class="ui-card p-6 md:p-8">
                        <h2 class="font-display text-lg font-bold text-primary">3. Representative Details</h2>

                        <div class="mt-6 space-y-5">
                            <div class="space-y-1.5">
                                <label for="full_name_corporate" class="block text-sm font-medium leading-none">Representative Name <span class="text-[#CC001F]" aria-hidden="true">*</span></label>
                                <input id="full_name_corporate" name="full_name" type="text" value="{{ old('full_name') }}" maxlength="160" autocomplete="name" data-required-when-visible @if ($errors->has('full_name')) aria-invalid="true" @endif class="field-control">
                                @error('full_name')
                                    <p class="text-xs text-[#CC001F]">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="grid gap-4 md:grid-cols-2">
                                <div class="space-y-1.5">
                                    <label for="email_corporate" class="block text-sm font-medium leading-none">Representative Email <span class="text-[#CC001F]" aria-hidden="true">*</span></label>
                                    <input id="email_corporate" name="email" type="email" value="{{ old('email') }}" maxlength="255" autocomplete="email" data-required-when-visible @if ($errors->has('email')) aria-invalid="true" @endif class="field-control">
                                    @error('email')
                                        <p class="text-xs text-[#CC001F]">{{ $message }}</p>
                                    @enderror
                                </div>

                                {{-- Same country-code widget as "Your details" above, wired
                                     identically by the shared JS below — JS-only, same as
                                     the individual Mobile field. --}}
                                <div class="space-y-1.5">
                                    <label class="block text-sm font-medium leading-none">
                                        Representative Phone <span class="text-[#CC001F]" aria-hidden="true">*</span>
                                    </label>

                                    <div data-mobile-widget class="flex gap-2">
                                        <select data-mobile-country class="field-control w-32 shrink-0 sm:w-40" aria-label="Country code">
                                            @foreach ($mobileCountries as $country)
                                                <option value="{{ $country['code'] }}" @selected($country['code'] === '+94')>{{ $country['flag'] }} {{ $country['name'] }} ({{ $country['code'] }})</option>
                                            @endforeach
                                        </select>
                                        <input type="tel" data-mobile-number inputmode="tel" placeholder="77 123 4567" class="field-control min-w-0 flex-1" aria-label="Representative mobile number">
                                        <input type="hidden" data-mobile-combined data-required-when-visible>
                                    </div>

                                    @error('mobile')
                                        <p class="text-xs text-[#CC001F]">{{ $message }}</p>
                                    @enderror
                                </div>
                            </div>

                            <div class="space-y-1.5">
                                <label for="aviation_organisation_corporate" class="block text-sm font-medium leading-none">Position / Designation <span class="text-[#CC001F]" aria-hidden="true">*</span></label>
                                <input id="aviation_organisation_corporate" name="aviation_organisation" type="text" value="{{ old('aviation_organisation') }}" maxlength="200" data-required-when-visible @if ($errors->has('aviation_organisation')) aria-invalid="true" @endif class="field-control">
                                @error('aviation_organisation')
                                    <p class="text-xs text-[#CC001F]">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>
                    </div>

                    {{-- Student / Professional / Veteran: proof required. Enthusiast: no
                         proof at all. Corporate: its own "Supporting Document" card below. --}}
                    <div data-category-section-hide-for="{{ MembershipCategory::CODE_ENTHUSIAST }},{{ MembershipCategory::CODE_CORPORATE }}" class="ui-card p-6 md:p-8">
                        <h2 class="font-display text-lg font-bold text-primary">4. Aviation eligibility proof</h2>
                        <p class="mt-1 text-sm text-muted-foreground">Required for every category — documents that show your connection to aviation.</p>

                        <div class="mt-4 space-y-1.5" data-proof-widget>
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
                                    data-proof-upload
                                    data-required-when-visible
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

                    {{-- Corporate — Section 3: Supporting Document. Same private
                         proof_documents upload mechanism, no new document type. --}}
                    <div data-category-section="{{ MembershipCategory::CODE_CORPORATE }}" class="ui-card p-6 md:p-8">
                        <h2 class="font-display text-lg font-bold text-primary">4. Supporting Document</h2>
                        <p class="mt-1 text-sm text-muted-foreground">Company membership/request letter, on company letterhead.</p>

                        <div class="mt-4 space-y-1.5" data-proof-widget>
                            <label for="proof_documents_corporate" class="block text-sm font-medium leading-none">Company Membership / Request Letter</label>

                            <label for="proof_documents_corporate" class="flex cursor-pointer flex-col items-center gap-2 rounded-lg border-2 border-dashed border-border bg-muted/40 p-6 text-center transition-colors hover:border-[#CC001F]/50 hover:bg-[#CC001F]/5">
                                <span class="grid h-11 w-11 place-items-center rounded-full bg-[#4D4D4D] text-white">
                                    <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12"/><path d="m7 8 5-5 5 5"/><path d="M5 21h14a2 2 0 0 0 2-2v-5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v5a2 2 0 0 0 2 2Z"/></svg>
                                </span>
                                <span class="text-sm font-medium text-primary">Click to attach the company letter</span>
                                <span class="text-xs text-muted-foreground">
                                    Accepted: PDF, JPG or PNG. Max {{ round($proof['max_kb'] / 1024, 1) }}MB per file, up to {{ $proof['max_files'] }} files.
                                </span>

                                <input
                                    id="proof_documents_corporate"
                                    name="proof_documents[]"
                                    type="file"
                                    multiple
                                    data-proof-upload
                                    data-required-when-visible
                                    accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                                    class="sr-only"
                                >
                            </label>

                            <p data-file-list class="text-xs text-muted-foreground" aria-live="polite"></p>

                            @foreach (collect($errors->get('proof_documents'))->merge(collect($errors->get('proof_documents.*'))->flatten())->unique() as $message)
                                <p class="text-xs text-[#CC001F]">{{ $message }}</p>
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

                {{-- Progressive enhancement (no library): show only the selected category's
                     fields/sections. Without JavaScript every S/P/V/E field stays visible
                     and the server applies the same rules, exactly as before — Corporate's
                     own duplicate-labelled fields are the one part of this form that need
                     JavaScript to avoid two same-named inputs both being visible at once
                     (same trade-off the mobile country-code widget already makes). --}}
                <script>
                    (function () {
                        var form = document.getElementById('application-form');
                        var labels = {
                            S: { role: 'Course Name', organisation: 'Training Institute' },
                            P: { role: 'Occupation', organisation: 'Employer / Organisation' },
                            V: { role: 'Position Held', organisation: 'Most Recent Aviation Employer' }
                        };

                        // A hidden section's fields must never submit: disabling excludes
                        // them from the request entirely, so Corporate's duplicated field
                        // names (full_name/email/mobile/address/aviation_role/
                        // aviation_organisation/proof_documents) never collide with the one
                        // currently-visible copy.
                        function setSectionVisibility(section, active) {
                            section.hidden = !active;
                            section.querySelectorAll('input, select, textarea').forEach(function (field) {
                                field.disabled = !active;
                            });
                            section.querySelectorAll('[data-required-when-visible]').forEach(function (field) {
                                field.required = active;
                            });
                        }

                        function apply() {
                            var checked = form.querySelector('input[name="category"]:checked');
                            var code = checked ? checked.value : null;

                            // Shown only for the listed code (existing mechanism).
                            form.querySelectorAll('[data-category-section]').forEach(function (section) {
                                var codes = section.getAttribute('data-category-section').split(',');
                                setSectionVisibility(section, codes.indexOf(code) !== -1);
                            });

                            // Shown for every code except the listed one(s) — the inverse,
                            // for Enthusiast's "no proof" and Corporate's "own sections
                            // instead of these" cases.
                            form.querySelectorAll('[data-category-section-hide-for]').forEach(function (section) {
                                var codes = section.getAttribute('data-category-section-hide-for').split(',');
                                setSectionVisibility(section, codes.indexOf(code) === -1);
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
                        // back to the applicant, for every upload widget on the page (the
                        // individual-category one and Corporate's own). No validation
                        // happens here.
                        form.querySelectorAll('[data-proof-widget]').forEach(function (widget) {
                            var proof = widget.querySelector('[data-proof-upload]');
                            var fileList = widget.querySelector('[data-file-list]');
                            if (!proof || !fileList) { return; }
                            proof.addEventListener('change', function () {
                                var names = Array.prototype.map.call(proof.files, function (file) { return file.name; });
                                fileList.textContent = names.length ? names.length + ' file(s) selected: ' + names.join(', ') : '';
                            });
                        });

                        // Mobile country-code widget, wired identically for every copy on
                        // the page (the individual-category one, and Corporate's own). The
                        // combined field only gets name="mobile" here, once JS has actually
                        // run — with JS off, the individual card's own <noscript> field is
                        // the only "mobile" input submitted, exactly as before this widget
                        // existed.
                        form.querySelectorAll('[data-mobile-widget]').forEach(function (widget) {
                            var mobileCountry = widget.querySelector('[data-mobile-country]');
                            var mobileNumber = widget.querySelector('[data-mobile-number]');
                            var mobileCombined = widget.querySelector('[data-mobile-combined]');
                            if (!mobileCountry || !mobileNumber || !mobileCombined) { return; }

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
                        });
                    })();
                </script>
            @endif
        </div>
    </section>
</x-layouts.public>
