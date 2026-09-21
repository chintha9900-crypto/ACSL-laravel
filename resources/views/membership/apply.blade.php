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
        Join the <span class="text-gradient">community.</span>
    </x-public.hero>

    <section class="container mx-auto max-w-5xl px-4 py-16 md:py-20 lg:px-8">
        <div class="space-y-8">
            <div class="ui-card p-6 md:p-8">
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
                <form id="application-form" method="POST" action="{{ route('membership.apply.store') }}" enctype="multipart/form-data" class="space-y-8">
                    @csrf

                    <fieldset>
                        <legend class="sr-only">Choose your membership category</legend>

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
                                    <span class="block rounded-lg border border-border bg-background px-4 py-4 text-center font-display text-sm font-semibold text-primary transition-colors hover:border-primary/60 peer-checked:border-primary peer-checked:bg-primary peer-checked:text-primary-foreground peer-checked:shadow-elegant peer-focus-visible:ring-2 peer-focus-visible:ring-ring peer-focus-visible:ring-offset-2 md:text-base">
                                        {{ $category->name }}
                                    </span>
                                </label>
                            @endforeach
                        </div>

                        @error('category')
                            <p class="mt-2 text-xs text-destructive">{{ $message }}</p>
                        @enderror
                    </fieldset>

                    <div class="ui-card p-6 md:p-8">
                        <h2 class="font-display text-2xl font-bold text-primary">Apply for membership</h2>
                        <p class="mt-1 text-sm text-muted-foreground">
                            All fields are required. Upload your aviation eligibility proof to complete your application.
                        </p>

                        @if ($errors->any())
                            <div class="mt-6 w-full rounded-lg border border-destructive/50 px-4 py-3 text-sm text-destructive" role="alert">
                                Please correct the highlighted fields and try again.
                            </div>
                        @endif

                        <div class="mt-6 space-y-5">
                            <x-form.input name="full_name" label="Full Name" autocomplete="name" maxlength="160" />

                            <div class="grid gap-4 md:grid-cols-2">
                                <x-form.input name="email" label="Email" type="email" autocomplete="email" maxlength="255" />
                                <x-form.input name="mobile" label="Mobile" type="tel" autocomplete="tel" maxlength="40"
                                    hint="Include the country code if you are outside Sri Lanka, for example +94 77 123 4567." />
                            </div>

                            <x-form.input name="address" label="Address" type="textarea" rows="2" maxlength="400" autocomplete="street-address" />

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

                            <div class="space-y-1.5">
                                <label for="proof_documents" class="block text-sm font-medium leading-none">Upload Proof</label>

                                <div class="rounded-lg border border-dashed border-border p-4">
                                    <div class="flex flex-wrap items-center justify-between gap-3">
                                        <div class="text-sm">
                                            <p class="font-medium">Attach supporting documents</p>
                                            <p id="proof_documents-hint" class="text-xs text-muted-foreground">
                                                Accepted: PDF, JPG or PNG. Max {{ round($proof['max_kb'] / 1024, 1) }}MB per file, up to {{ $proof['max_files'] }} files.
                                            </p>
                                        </div>
                                        <input
                                            id="proof_documents"
                                            name="proof_documents[]"
                                            type="file"
                                            multiple
                                            required
                                            accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                                            aria-describedby="proof_documents-hint @if ($errors->has('proof_documents') || $errors->has('proof_documents.*')) proof_documents-error @endif"
                                            @if ($errors->has('proof_documents') || $errors->has('proof_documents.*')) aria-invalid="true" @endif
                                            class="min-w-0 max-w-full cursor-pointer text-sm text-muted-foreground file:mr-3 file:h-8 file:cursor-pointer file:rounded-md file:border file:border-input file:bg-background file:px-3 file:text-xs file:font-medium file:text-foreground file:shadow-sm hover:file:bg-accent focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                                        >
                                    </div>
                                </div>

                                @foreach (collect($errors->get('proof_documents'))->merge(collect($errors->get('proof_documents.*'))->flatten())->unique() as $message)
                                    <p id="{{ $loop->first ? 'proof_documents-error' : 'proof_documents-error-'.$loop->index }}" class="text-xs text-destructive">{{ $message }}</p>
                                @endforeach
                            </div>

                            <button type="submit" class="btn btn-lg btn-gradient w-full">Submit</button>
                        </div>
                    </div>
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
                    })();
                </script>
            @endif
        </div>
    </section>
</x-layouts.public>
