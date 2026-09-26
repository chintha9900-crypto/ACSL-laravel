{{--
    Membership Benefits — content and pricing hard-coded to match the ACI
    reference screenshot exactly, per explicit instruction. This deliberately
    supersedes this page's earlier "no hard-coded prices, no invented
    benefits, Professional = one plan" approach (docs/frontend/08 C-04): the
    three fixed prices, the Professional 3-tier structure, and every feature
    bullet below are copied verbatim from the reference, not derived from
    `membership_plans`/`membership_categories`. The controller is untouched
    and still passes `$categories`/`$plansByCategory`/`$introductoryMonths`;
    only the real category's id/slug (for the Apply Now link and the
    S/P/V-keyed card content lookup) is still used from it.

    Brand colours only: #CC001F red, #4D4D4D dark gray, #666666 gray, white —
    no blue/yellow/green from the reference.
--}}
@use('Illuminate\Support\Str')
<x-layouts.public title="Membership Benefits">
    <x-public.hero>
        <x-slot:badge>Membership Categories</x-slot:badge>
        <x-slot:lead>
            Three tailored categories for students, working professionals and veterans of the aviation community.
        </x-slot:lead>
        Choose your <span class="text-[#CC001F]">membership.</span>
    </x-public.hero>

    {{-- Categories --}}
    <section class="border-t border-border">
        <div class="container mx-auto px-4 py-16 md:py-20 lg:px-8">
            @if ($categories->isEmpty())
                <p class="mx-auto max-w-md rounded-lg border border-border bg-muted px-4 py-3 text-center text-sm text-foreground" role="status">
                    Membership categories are not published yet. Please check back soon.
                </p>
            @else
                @php
                    $offer = 'Launching Offer — First 6 Months FREE';

                    $icons = [
                        'S' => '<path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/>',
                        'P' => '<rect width="20" height="14" x="2" y="7" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>',
                        'V' => '<circle cx="12" cy="8" r="6"/><path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11"/>',
                    ];

                    // Exact wording/prices/feature lists from the approved reference
                    // screenshot — not database-driven, per explicit instruction.
                    $cardContent = [
                        'S' => [
                            'heading' => 'Aviation Student Membership',
                            'subtitle' => 'For aviation students',
                            'amount' => '3,500',
                            'period' => 'per year',
                            'features' => [
                                'Membership card',
                                'Vendor discounts (computers / insurance / medical / travel)',
                                'Free electronic logbook (Flying / engineering students)',
                                '50% discount on ATPL Question Bank',
                                'Free aviation events',
                                'Special member pricing for ATPL training',
                                'Career development opportunities',
                                'Networking events',
                            ],
                        ],
                        'V' => [
                            'heading' => 'Veteran Aviation Professional',
                            'subtitle' => 'For retired & highly experienced aviation professionals',
                            'amount' => '3,000',
                            'period' => 'per year',
                            'features' => [
                                'Veteran networking events',
                                'Guest speaking opportunities',
                                'Aviation community recognition',
                                'Mentoring opportunities',
                                'Exclusive gatherings',
                                'Vendor discounts',
                            ],
                        ],
                        'P' => [
                            'heading' => 'Aviation Professional Membership',
                            'subtitle' => 'For working aviation professionals — choose from three tiers',
                            'tiers' => [
                                [
                                    'eyebrow' => 'Essential',
                                    'title' => 'Core Package',
                                    'amount' => '5,000',
                                    'period' => 'per year',
                                    'features' => [
                                        'Membership card',
                                        'Vendor discounts (computers / insurance / medical / travel)',
                                        'Free aviation events',
                                        'Networking events',
                                    ],
                                ],
                                [
                                    'eyebrow' => 'Popular',
                                    'title' => 'Premier Package',
                                    'amount' => '10,000',
                                    'period' => 'per year',
                                    'features' => [
                                        'All Core Package benefits',
                                        'Special member pricing for ATPL training',
                                        'Career development opportunities',
                                        'Access job portal and apply',
                                        'Priority event registration',
                                    ],
                                ],
                                [
                                    'eyebrow' => 'Prestige',
                                    'title' => 'Inner-Circle (Prestige)',
                                    'amount' => '25,000',
                                    'period' => 'per year',
                                    'features' => [
                                        'All Premier Package benefits',
                                        'Exclusive prestige-tier gatherings',
                                        '1-on-1 career mentoring sessions',
                                        'VIP access to industry events',
                                        'Personalised aviation advisory',
                                        'Invitations to private roundtables',
                                    ],
                                ],
                            ],
                        ],
                    ];
                @endphp

                {{-- Grid placement is keyed by category code, not loop order, so the
                     Student/Veteran-left, Professional-right arrangement from the
                     reference holds regardless of the DB's display order. --}}
                <div class="mx-auto grid max-w-5xl gap-5 lg:grid-cols-2 lg:grid-rows-2">
                    @foreach ($categories as $category)
                        @php
                            $content = $cardContent[$category->code] ?? null;
                            $slotClasses = match ($category->code) {
                                'S' => 'lg:col-start-1 lg:row-start-1',
                                'V' => 'lg:col-start-1 lg:row-start-2',
                                'P' => 'lg:col-start-2 lg:row-start-1 lg:row-span-2',
                                default => '',
                            };
                        @endphp
                        <div class="flex h-full flex-col rounded-xl border-l-4 border-[#CC001F] bg-[#4D4D4D] p-6 text-white shadow-card md:p-7 {{ $slotClasses }}">
                            @if ($content)
                                <div class="flex items-center gap-3">
                                    <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-white/15">
                                        <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $icons[$category->code] ?? $icons['P'] !!}</svg>
                                    </span>
                                    <h3 class="font-display text-xl font-bold">{{ $content['heading'] }}</h3>
                                </div>
                                <p class="mt-3 text-sm leading-relaxed text-white/75">{{ $content['subtitle'] }}</p>

                                <div class="mt-4 rounded-lg bg-white/10 px-3 py-2 text-xs font-semibold uppercase tracking-wide text-white/90">
                                    {{ $offer }}
                                </div>

                                @if (isset($content['tiers']))
                                    {{-- Professional: three separately-priced tiers. --}}
                                    @foreach ($content['tiers'] as $tier)
                                        <div class="mt-4 rounded-lg bg-white/10 p-4">
                                            <div class="flex items-start justify-between gap-3">
                                                <div>
                                                    <p class="text-[10px] font-bold uppercase tracking-wider text-[#CC001F]">{{ $tier['eyebrow'] }}</p>
                                                    <p class="font-display text-base font-bold">{{ $tier['title'] }}</p>
                                                </div>
                                                <div class="shrink-0 text-right">
                                                    <p class="font-display text-lg font-bold">LKR {{ $tier['amount'] }}</p>
                                                    <p class="text-[10px] text-white/60">{{ $tier['period'] }}</p>
                                                </div>
                                            </div>
                                            <ul class="mt-3 space-y-1.5 text-xs text-white/85">
                                                @foreach ($tier['features'] as $feature)
                                                    <li class="flex items-start gap-1.5">
                                                        <svg class="mt-0.5 h-3.5 w-3.5 shrink-0 text-white" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
                                                        <span>{{ $feature }}</span>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        </div>
                                    @endforeach

                                    {{--
                                        Professional eligibility check — same native
                                        <details>/<summary> + vanilla-JS gate pattern as
                                        Student's, kept visually identical. Apply Now only
                                        appears once both Yes/No questions are "Yes" and an
                                        occupation is chosen (with a required free-text
                                        answer if "Other").
                                    --}}
                                    <details class="mt-6 group">
                                        <summary class="btn btn-lg btn-brand w-full cursor-pointer list-none text-center [&::-webkit-details-marker]:hidden">Check Your Eligibility</summary>

                                        <div data-eligibility class="mt-4 space-y-5 rounded-lg bg-white/10 p-4 text-left">
                                            <fieldset>
                                                <legend class="text-sm font-semibold">Is your occupation related to aviation?</legend>
                                                <div class="mt-2 flex gap-5 text-sm text-white/90">
                                                    <label class="flex items-center gap-2"><input type="radio" name="professional-eligibility-related" value="yes" class="h-4 w-4"> Yes</label>
                                                    <label class="flex items-center gap-2"><input type="radio" name="professional-eligibility-related" value="no" class="h-4 w-4"> No</label>
                                                </div>
                                            </fieldset>

                                            <div>
                                                <label for="professional-eligibility-occupation" class="text-sm font-semibold">What is your occupation?</label>
                                                <select id="professional-eligibility-occupation" data-eligibility-area class="field-control mt-2 bg-white text-[#4D4D4D]">
                                                    <option value="">Select an occupation</option>
                                                    <option>Pilot / Captain</option>
                                                    <option>Aircraft Maintenance Engineer</option>
                                                    <option>Air Traffic Controller</option>
                                                    <option>Cabin Crew / Senior Cabin Crew</option>
                                                    <option>Aviation Manager / Executive</option>
                                                    <option>Flight Instructor</option>
                                                    <option>Airport Operations Manager</option>
                                                    <option>Aviation Regulator</option>
                                                    <option>Other</option>
                                                </select>

                                                <div data-eligibility-other hidden class="mt-2">
                                                    <label for="professional-eligibility-occupation-other" class="sr-only">Please specify your occupation</label>
                                                    <input type="text" id="professional-eligibility-occupation-other" placeholder="Please specify" class="field-control bg-white text-[#4D4D4D]">
                                                </div>
                                            </div>

                                            <fieldset>
                                                <legend class="text-sm font-semibold">Do you have proof of occupation? (Work ID or letter)</legend>
                                                <div class="mt-2 flex gap-5 text-sm text-white/90">
                                                    <label class="flex items-center gap-2"><input type="radio" name="professional-eligibility-proof" value="yes" class="h-4 w-4"> Yes</label>
                                                    <label class="flex items-center gap-2"><input type="radio" name="professional-eligibility-proof" value="no" class="h-4 w-4"> No</label>
                                                </div>
                                            </fieldset>

                                            <div>
                                                <a href="{{ route('membership.apply', ['category' => $category->slug()]) }}" data-eligibility-eligible hidden class="btn btn-lg btn-brand w-full">Apply Now</a>
                                                <p data-eligibility-ineligible hidden role="status" class="rounded-lg border border-white/20 bg-white/5 px-4 py-3 text-sm text-white/85">
                                                    Based on your answers, you may not currently meet the professional membership eligibility criteria. If your situation changes, you're welcome to check again — or contact ACI for guidance.
                                                </p>
                                            </div>
                                        </div>
                                    </details>
                                @else
                                    <p class="mt-5">
                                        <span class="font-display text-3xl font-bold">LKR {{ $content['amount'] }}</span>
                                        <span class="ml-1 text-sm text-white/70">{{ $content['period'] }}</span>
                                    </p>

                                    <ul class="mt-5 space-y-2.5 text-sm text-white/85">
                                        @foreach ($content['features'] as $feature)
                                            <li class="flex items-start gap-2">
                                                <svg class="mt-0.5 h-4 w-4 shrink-0 text-white" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
                                                <span>{{ $feature }}</span>
                                            </li>
                                        @endforeach
                                    </ul>

                                    @if ($category->code === 'S')
                                        {{--
                                            Student eligibility check — native <details>/<summary>
                                            disclosure (same zero-JS pattern already used for the
                                            header's mobile menu and the FAQ accordion), plus a small
                                            vanilla-JS gate (no Alpine/package: none is installed).
                                            Apply Now only appears once both Yes/No questions are
                                            answered "Yes" and a study area is selected; any "No"
                                            shows a polite ineligibility message instead.
                                        --}}
                                        <details class="mt-6 group">
                                            <summary class="btn btn-lg btn-brand w-full cursor-pointer list-none text-center [&::-webkit-details-marker]:hidden">Check Eligibility</summary>

                                            <div data-eligibility class="mt-4 space-y-5 rounded-lg bg-white/10 p-4 text-left">
                                                <fieldset>
                                                    <legend class="text-sm font-semibold">Are you currently enrolled as an aviation student?</legend>
                                                    <div class="mt-2 flex gap-5 text-sm text-white/90">
                                                        <label class="flex items-center gap-2"><input type="radio" name="student-eligibility-enrolled" value="yes" class="h-4 w-4"> Yes</label>
                                                        <label class="flex items-center gap-2"><input type="radio" name="student-eligibility-enrolled" value="no" class="h-4 w-4"> No</label>
                                                    </div>
                                                </fieldset>

                                                <div>
                                                    <label for="student-eligibility-area" class="text-sm font-semibold">Which area do you study?</label>
                                                    <select id="student-eligibility-area" data-eligibility-area class="field-control mt-2 bg-white text-[#4D4D4D]">
                                                        <option value="">Select an area</option>
                                                        <option>Piloting / Flight Training</option>
                                                        <option>Aircraft Maintenance Engineering</option>
                                                        <option>Aviation Management</option>
                                                        <option>Air Traffic Control</option>
                                                        <option>Aerospace / Aeronautical Engineering</option>
                                                        <option>Cabin Crew Training</option>
                                                        <option>Airport Operations</option>
                                                        <option>Other</option>
                                                    </select>
                                                    <p class="mt-1.5 text-xs text-white/70">You should be studying an aviation-related subject for at least 3 months to qualify for student membership.</p>
                                                </div>

                                                <fieldset>
                                                    <legend class="text-sm font-semibold">Do you have proof of enrollment? (Student ID / letter)</legend>
                                                    <div class="mt-2 flex gap-5 text-sm text-white/90">
                                                        <label class="flex items-center gap-2"><input type="radio" name="student-eligibility-proof" value="yes" class="h-4 w-4"> Yes</label>
                                                        <label class="flex items-center gap-2"><input type="radio" name="student-eligibility-proof" value="no" class="h-4 w-4"> No</label>
                                                    </div>
                                                </fieldset>

                                                <div>
                                                    <a href="{{ route('membership.apply', ['category' => $category->slug()]) }}" data-eligibility-eligible hidden class="btn btn-lg btn-brand w-full">Apply Now</a>
                                                    <p data-eligibility-ineligible hidden role="status" class="rounded-lg border border-white/20 bg-white/5 px-4 py-3 text-sm text-white/85">
                                                        Based on your answers, you may not currently meet the student membership eligibility criteria. If your situation changes, you're welcome to check again — or contact ACI for guidance.
                                                    </p>
                                                </div>
                                            </div>
                                        </details>
                                    @elseif ($category->code === 'V')
                                        {{--
                                            Veteran eligibility check — same pattern/classes as
                                            Student's and Professional's, wording adjusted.
                                        --}}
                                        <details class="mt-6 group">
                                            <summary class="btn btn-lg btn-brand w-full cursor-pointer list-none text-center [&::-webkit-details-marker]:hidden">Check Your Eligibility</summary>

                                            <div data-eligibility class="mt-4 space-y-5 rounded-lg bg-white/10 p-4 text-left">
                                                <fieldset>
                                                    <legend class="text-sm font-semibold">Have you worked in an aviation-related position for at least 3 years?</legend>
                                                    <div class="mt-2 flex gap-5 text-sm text-white/90">
                                                        <label class="flex items-center gap-2"><input type="radio" name="veteran-eligibility-experience" value="yes" class="h-4 w-4"> Yes</label>
                                                        <label class="flex items-center gap-2"><input type="radio" name="veteran-eligibility-experience" value="no" class="h-4 w-4"> No</label>
                                                    </div>
                                                </fieldset>

                                                <div>
                                                    <label for="veteran-eligibility-profession" class="text-sm font-semibold">What was your profession?</label>
                                                    <select id="veteran-eligibility-profession" data-eligibility-area class="field-control mt-2 bg-white text-[#4D4D4D]">
                                                        <option value="">Select a profession</option>
                                                        <option>Pilot / Captain</option>
                                                        <option>Aircraft Maintenance Engineer</option>
                                                        <option>Air Traffic Controller</option>
                                                        <option>Cabin Crew / Senior Cabin Crew</option>
                                                        <option>Aviation Manager / Executive</option>
                                                        <option>Flight Instructor</option>
                                                        <option>Airport Operations Manager</option>
                                                        <option>Aviation Regulator</option>
                                                        <option>Other</option>
                                                    </select>

                                                    <div data-eligibility-other hidden class="mt-2">
                                                        <label for="veteran-eligibility-profession-other" class="sr-only">Please specify your profession</label>
                                                        <input type="text" id="veteran-eligibility-profession-other" placeholder="Please specify" class="field-control bg-white text-[#4D4D4D]">
                                                    </div>
                                                </div>

                                                <fieldset>
                                                    <legend class="text-sm font-semibold">Do you have proof of occupation? (Work ID or letter)</legend>
                                                    <div class="mt-2 flex gap-5 text-sm text-white/90">
                                                        <label class="flex items-center gap-2"><input type="radio" name="veteran-eligibility-proof" value="yes" class="h-4 w-4"> Yes</label>
                                                        <label class="flex items-center gap-2"><input type="radio" name="veteran-eligibility-proof" value="no" class="h-4 w-4"> No</label>
                                                    </div>
                                                </fieldset>

                                                <div>
                                                    <a href="{{ route('membership.apply', ['category' => $category->slug()]) }}" data-eligibility-eligible hidden class="btn btn-lg btn-brand w-full">Apply Now</a>
                                                    <p data-eligibility-ineligible hidden role="status" class="rounded-lg border border-white/20 bg-white/5 px-4 py-3 text-sm text-white/85">
                                                        Based on your answers, you may not currently meet the veteran membership eligibility criteria. If your situation changes, you're welcome to check again — or contact ACI for guidance.
                                                    </p>
                                                </div>
                                            </div>
                                        </details>
                                    @else
                                        <a href="{{ route('membership.apply', ['category' => $category->slug()]) }}" class="btn btn-lg btn-brand mt-6">Apply Now</a>
                                    @endif
                                @endif
                            @else
                                {{-- Defensive fallback for a category code outside the fixed
                                     Student/Professional/Veteran set — not expected in practice. --}}
                                <h3 class="font-display text-xl font-bold">{{ $category->name }}</h3>
                                <a href="{{ route('membership.apply', ['category' => $category->slug()]) }}" class="btn btn-lg btn-brand mt-6">Apply Now</a>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </section>

    {{-- Application process --}}
    <section class="border-t border-border bg-muted/50">
        <div class="container mx-auto px-4 py-16 md:py-20 lg:px-8">
            <div class="mx-auto max-w-2xl text-center">
                <h2 class="font-display text-3xl font-bold tracking-tight text-primary md:text-4xl">Application Process</h2>
                <p class="mt-3 text-lg text-muted-foreground">A few easy steps to becoming a member.</p>
            </div>

            @php
                $steps = [
                    ['title' => 'Submit Application', 'text' => 'Complete the online application with proof of your connection to aviation.', 'path' => '<path d="M3.4 20.4 21 12 3.4 3.6 3.4 10 15 12 3.4 14z"/>'],
                    ['title' => 'Application Review', 'text' => 'ACI reviews your application and may request more details if needed.', 'path' => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>'],
                    ['title' => 'Approval &amp; Activation', 'text' => 'Once approved, your membership is activated and your membership number is issued.', 'path' => '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>'],
                    ['title' => 'Free Introductory Period', 'text' => 'Your first '.$introductoryMonths.' '.Str::plural('month', $introductoryMonths).' are free — no payment required.', 'path' => '<rect width="18" height="18" x="3" y="4" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>'],
                    ['title' => 'Renewal Reminder', 'text' => 'Before your term ends, you\'ll get a reminder to renew.', 'path' => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>'],
                    ['title' => 'Renew to Continue', 'text' => 'Renew online (normally for 12 months) to keep your membership active.', 'path' => '<path d="m9 12 2 2 4-4"/><path d="M3.85 8.62a4 4 0 0 1 4.78-4.77 4 4 0 0 1 6.74 0 4 4 0 0 1 4.78 4.78 4 4 0 0 1 0 6.74 4 4 0 0 1-4.77 4.78 4 4 0 0 1-6.75 0 4 4 0 0 1-4.78-4.77 4 4 0 0 1 0-6.76Z"/>'],
                ];
            @endphp

            <div class="mx-auto mt-10 max-w-6xl rounded-xl bg-[#4D4D4D] p-6 shadow-card md:p-10">
                <div class="grid grid-cols-1 gap-8 sm:grid-cols-2 lg:flex lg:items-start lg:gap-0">
                    @foreach ($steps as $index => $step)
                        <div class="flex flex-1 flex-col px-2 text-left text-white">
                            <div class="flex items-start justify-between gap-2">
                                <svg class="h-5 w-5 shrink-0 text-white/70" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $step['path'] !!}</svg>
                                <span class="font-display text-3xl font-bold leading-none">{{ $index + 1 }}</span>
                            </div>
                            <h3 class="mt-3 text-sm font-semibold">{!! $step['title'] !!}</h3>
                            <p class="mt-2 text-xs leading-relaxed text-white/70">{{ $step['text'] }}</p>
                        </div>

                        @if (! $loop->last)
                            <div class="hidden items-start justify-center pt-1 lg:flex">
                                <svg class="h-6 w-6 text-white/60" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
                            </div>
                        @endif
                    @endforeach
                </div>
            </div>

            <div class="mt-10 flex justify-center">
                <a href="{{ route('membership.apply') }}" class="btn btn-lg btn-brand">Apply Now</a>
            </div>
        </div>
    </section>

    <script>
        // Eligibility gates (Student, Professional, Veteran) — plain JS, no
        // framework: one shared gate wires every `[data-eligibility]` block
        // on the page. Apply Now only appears once every Yes/No question is
        // "Yes" and the select has a value (plus, when "Other" is chosen, a
        // non-empty free-text answer); any "No" shows the ineligible message.
        (function () {
            document.querySelectorAll('[data-eligibility]').forEach(function (root) {
                var eligible = root.querySelector('[data-eligibility-eligible]');
                var ineligible = root.querySelector('[data-eligibility-ineligible]');
                var area = root.querySelector('[data-eligibility-area]');
                var other = root.querySelector('[data-eligibility-other]');
                var otherInput = other ? other.querySelector('input') : null;

                var radioNames = [];
                root.querySelectorAll('input[type="radio"]').forEach(function (input) {
                    if (radioNames.indexOf(input.name) === -1) {
                        radioNames.push(input.name);
                    }
                });

                function evaluate() {
                    var hasNo = false;
                    var allAnswered = true;

                    radioNames.forEach(function (name) {
                        var checked = root.querySelector('input[name="' + name + '"]:checked');
                        if (!checked) { allAnswered = false; return; }
                        if (checked.value === 'no') { hasNo = true; }
                    });

                    var isOther = !!area && area.value === 'Other';
                    if (other) { other.hidden = !isOther; }

                    var areaValid = !area || (area.value !== '' && (!isOther || (otherInput && otherInput.value.trim() !== '')));

                    eligible.hidden = !(allAnswered && !hasNo && areaValid);
                    ineligible.hidden = !hasNo;
                }

                root.addEventListener('change', evaluate);
                root.addEventListener('input', evaluate);
                evaluate();
            });
        })();
    </script>
</x-layouts.public>
