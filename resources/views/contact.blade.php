{{--
    Contact — docs/frontend/03_PUBLIC_PAGES.md §A14, adapted. No screenshot was
    actually attached with this request, so the layout below (contact details
    left, form right, matching this project's own documented A14 reference
    outline) is a best-effort standard contact-page arrangement, not a literal
    pixel match — flagged in the implementation report.

    The reference's hard-coded email/phone/address (`hello@acsl.lk`,
    `+94 11 234 5678`, "Colombo, Sri Lanka") are legacy, unverified and use the
    forbidden ACSL name (docs/frontend/08 C-02) — not carried over. No real ACI
    contact details exist anywhere in this project (checked config/.env — only
    Laravel's own placeholder `hello@example.com`), so those rows stay clearly
    marked as pending rather than inventing values.

    The form posts to a real route (ContactController@store, validated by
    StoreContactEnquiryRequest) and redirects to a genuine success page — but
    nothing is stored or emailed yet: there is no approved `contact_enquiries`
    table/model and no confirmed admin recipient address for this project.
    This limitation is disclosed in the implementation report.
--}}
<x-layouts.public title="Contact">
    <x-public.hero>
        <x-slot:badge>Contact</x-slot:badge>
        <x-slot:lead>
            Have a question about membership? Here's how to reach Aviation Club International.
        </x-slot:lead>
        Get in <span class="text-[#CC001F]">touch.</span>
    </x-public.hero>

    <section class="border-t border-border">
        <div class="container mx-auto max-w-5xl px-4 py-16 md:py-20 lg:px-8">
            <div class="grid gap-8 lg:grid-cols-5 lg:gap-10">
                {{-- Contact details --}}
                <div class="space-y-5 lg:col-span-2">
                    <h2 class="font-display text-xl font-bold text-primary">Contact details</h2>

                    <div class="ui-card p-6">
                        <x-ui.icon-tile size="sm">
                            <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 4h16v16H4z"/><path d="m22 6-10 7L2 6"/></svg>
                        </x-ui.icon-tile>
                        <h3 class="mt-4 font-display text-base font-semibold text-primary">Email</h3>
                        <p class="mt-1 text-sm text-muted-foreground">To be published by Aviation Club International.</p>
                    </div>

                    <div class="ui-card p-6">
                        <x-ui.icon-tile size="sm">
                            <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M13.4 2.6a2 2 0 0 1 2.8 0l1.2 1.2a2 2 0 0 1 0 2.8l-2 2a12.7 12.7 0 0 0 5 5l2-2a2 2 0 0 1 2.8 0l1.2 1.2a2 2 0 0 1 0 2.8l-1.8 1.8c-.7.7-1.7 1-2.7.8A18 18 0 0 1 3.4 6.5c-.2-1 .1-2 .8-2.7z"/></svg>
                        </x-ui.icon-tile>
                        <h3 class="mt-4 font-display text-base font-semibold text-primary">Phone</h3>
                        <p class="mt-1 text-sm text-muted-foreground">To be published by Aviation Club International.</p>
                    </div>

                    <div class="ui-card p-6">
                        <x-ui.icon-tile size="sm">
                            <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
                        </x-ui.icon-tile>
                        <h3 class="mt-4 font-display text-base font-semibold text-primary">Address</h3>
                        <p class="mt-1 text-sm text-muted-foreground">To be published by Aviation Club International.</p>
                    </div>
                </div>

                {{-- General inquiry form --}}
                <div class="ui-card p-6 lg:col-span-3 md:p-8">
                    <h2 class="font-display text-xl font-bold text-primary">General Inquiry</h2>
                    <p class="mt-1 text-sm text-muted-foreground">Send us a message and we'll get back to you.</p>

                    @if ($errors->any())
                        <div class="mt-6 w-full rounded-lg border border-[#CC001F]/50 bg-[#CC001F]/5 px-4 py-3 text-sm text-[#CC001F]" role="alert">
                            Please correct the highlighted fields and try again.
                        </div>
                    @endif

                    <form method="POST" action="{{ route('contact.store') }}" class="mt-6 space-y-5">
                        @csrf

                        <x-form.input name="name" label="Name" autocomplete="name" maxlength="160" />

                        <div class="grid gap-4 md:grid-cols-2">
                            <x-form.input name="email" label="Email" type="email" autocomplete="email" maxlength="255" />
                            <x-form.input name="phone" label="Phone" type="tel" autocomplete="tel" maxlength="40" :required="false" />
                        </div>

                        <x-form.input name="subject" label="Subject" maxlength="200" />

                        <x-form.input name="message" label="Message" type="textarea" rows="5" maxlength="5000" />

                        <div class="flex flex-col gap-3 sm:flex-row">
                            <button type="submit" class="btn btn-lg btn-brand flex-1">Submit</button>
                            <button type="reset" class="btn btn-lg border border-input bg-background shadow-sm hover:bg-accent hover:text-accent-foreground">Reset</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </section>
</x-layouts.public>
