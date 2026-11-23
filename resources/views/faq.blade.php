{{--
    Membership FAQ — docs/frontend/03_PUBLIC_PAGES.md §A6. The accordion is
    native <details>/<summary> (the same zero-JS disclosure pattern already
    used for the header's mobile menu) rather than the doc's Alpine-based
    `content.accordion`, since Alpine isn't installed and this task may not
    add packages. Every answer describes only what this application actually
    implements (introductory months come from settings, never hard-coded; no
    fee amounts are stated since those are configured per category/plan and
    shown on the Benefits page).
--}}
<x-layouts.public title="Membership FAQ">
    <x-public.hero>
        <x-slot:badge>FAQ</x-slot:badge>
        <x-slot:lead>
            Answers to the questions we're asked most about membership.
        </x-slot:lead>
        Common <span class="text-[#CC001F]">questions.</span>
    </x-public.hero>

    <section class="border-t border-border">
        <div class="site-container content-reading py-16 md:py-20">
            @php
                $faqs = [
                    [
                        'q' => 'Who can become a member?',
                        'a' => [
                            'Membership is open to anyone with an interest in or connection to aviation. Whether you are an aviation enthusiast of any age, a student, an active aviation professional, or a veteran who has served in the aviation industry, there is a place for you.',
                            'We also welcome school and university aviation clubs, as well as companies and organisations involved in the aviation industry. Simply apply under the membership category that best matches you.',
                        ],
                    ],
                    [
                        'q' => 'What are the membership categories?',
                        'categories' => [
                            'Aviation Enthusiast Membership' => 'Open to anyone with an interest in aviation, regardless of age or professional background.',
                            'Aviation Student Membership' => 'For students pursuing studies or training in aviation-related fields, including flight training, aircraft engineering, cabin crew, aviation management and other related disciplines.',
                            'Professional Membership' => 'For individuals currently working in the aviation industry, including pilots, aircraft maintenance engineers, air traffic controllers, cabin crew, aviation safety professionals, operations personnel, cargo specialists, trainers and aviation analysts.',
                            'Veteran Professional Membership' => 'For retired aviation professionals and highly experienced individuals who have made a significant contribution to the aviation industry.',
                            'Corporate Membership' => 'For organisations and businesses involved in aviation, including airlines, flying schools, maintenance, repair and overhaul (MRO) organisations, aviation service providers and other aviation-related companies.',
                            'School & University Club Membership' => 'For schools, colleges and universities interested in establishing or supporting aviation clubs and encouraging students to explore aviation.',
                        ],
                    ],
                    [
                        'q' => 'What do I need to apply?',
                        'a' => [
                            'Complete the membership application form with your personal details.',
                            'Students and aviation professionals must upload proof of their connection to aviation (e.g., an enrolment or course confirmation letter for students, or employment evidence for professionals).',
                            'Aviation enthusiasts do not need to provide any supporting evidence.',
                        ],
                    ],
                    [
                        'q' => 'How is my application approved?',
                        'a' => [
                            'The Aviation Club team reviews every membership application. If any information needs clarification, we may contact you to request additional details or supporting documents.',
                            'Approval and activation are two separate steps. Approval means your application has been accepted. Activation means your membership is officially active, giving you access to member benefits and services once the required activation process is completed.',
                        ],
                    ],
                    [
                        'q' => 'Is there really a free introductory period?',
                        'a' => 'Yes. Every newly activated member gets a free introductory period before any payment is due — there is no fee to get started as a member.',
                    ],
                    [
                        'q' => 'How does payment and renewal work?',
                        'a' => [
                            'Your introductory period is free. After that, renewing is a paid step: you\'ll see the renewal fee for your category on your Membership page when it\'s time to renew, pay by bank transfer, and submit your payment reference and evidence for the Aviation Club to confirm.',
                            'Renewal does not happen automatically; you choose when to renew.',
                        ],
                    ],
                    [
                        'q' => 'Will my membership number change when I renew?',
                        'a' => 'No. Your membership number is issued once, when your membership is first activated, and stays the same for as long as you remain a member including across every renewal.',
                    ],
                    [
                        'q' => 'How do I set up my account?',
                        'a' => 'Once your membership is activated, you\'ll receive an email with a one-time link to set your account password. After that, you can sign in any time from the Sign In page.',
                    ],
                    [
                        'q' => 'What is the digital membership card and QR code for?',
                        'a' => [
                            'Once you\'re signed in, your account includes a digital membership card with a QR code. Scanning it takes you to a page confirming your membership is currently active. It never reveals your email, phone or other personal details.',
                            'The card follows your real membership status: if your membership lapses it will show as not currently valid, and it becomes valid again as soon as you renew.',
                        ],
                    ],
                    [
                        'q' => 'What happens if my application is rejected?',
                        'a' => 'You\'ll be told if your application isn\'t approved. You\'re welcome to apply again whenever you\'re ready  there\'s no waiting period.',
                    ],
                    [
                        'q' => 'I forgot my password. What do I do?',
                        'a' => 'Use the "Forgot?" link on the Sign In page to reset your password by email.',
                    ],
                ];
            @endphp

            <div class="space-y-3">
                @foreach ($faqs as $faq)
                    <details class="group rounded-lg border border-border bg-background px-5 py-4">
                        <summary class="flex cursor-pointer list-none items-center justify-between gap-4 font-display text-base font-semibold text-primary [&::-webkit-details-marker]:hidden">
                            {{ $faq['q'] }}
                            <svg class="h-5 w-5 shrink-0 text-muted-foreground transition-transform group-open:rotate-180" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                        </summary>
                        @foreach ((array) ($faq['a'] ?? []) as $paragraph)
                            <p class="mt-3 text-sm leading-relaxed text-muted-foreground">{{ $paragraph }}</p>
                        @endforeach
                        @isset($faq['categories'])
                            <ol class="mt-3 list-decimal space-y-3 pl-5 text-sm leading-relaxed text-muted-foreground">
                                @foreach ($faq['categories'] as $name => $description)
                                    <li><strong>{{ $name }}</strong> – {{ $description }}</li>
                                @endforeach
                            </ol>
                        @endisset
                    </details>
                @endforeach
            </div>
        </div>
    </section>
</x-layouts.public>
