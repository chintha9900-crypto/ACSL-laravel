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
        <div class="container mx-auto max-w-3xl px-4 py-16 md:py-20 lg:px-8">
            @php
                $faqs = [
                    [
                        'q' => 'Who can become a member?',
                        'a' => 'Membership is open to anyone who genuinely works, studies or takes part in the aviation field. You apply under whichever category best matches your situation.',
                    ],
                    [
                        'q' => 'What are the membership categories?',
                        'a' => 'There are three: Student (currently studying in an aviation-related course), Professional (working in the aviation industry) and Veteran (previous aviation experience). Each category asks for a few category-specific details on the application form.',
                    ],
                    [
                        'q' => 'What do I need to apply?',
                        'a' => 'Fill in the application form with your details and upload proof of your connection to aviation (for example a course letter, employment evidence, or similar). You\'ll get a link to track your application\'s status by email.',
                    ],
                    [
                        'q' => 'How is my application approved?',
                        'a' => 'ACI reviews every application. They may ask you for more details if something needs clarifying. Approval and activation are two separate steps — being approved means your application has been accepted; activation is when your membership actually starts.',
                    ],
                    [
                        'q' => 'Is there really a free introductory period?',
                        'a' => 'Yes. Every newly activated member gets a free introductory period before any payment is due — there is no fee to get started as a member.',
                    ],
                    [
                        'q' => 'How does payment and renewal work?',
                        'a' => 'Your introductory period is free. After that, renewing is a paid step: you\'ll see the renewal fee for your category on your Membership page when it\'s time to renew, pay by bank transfer, and submit your payment reference and evidence for ACI to confirm. Renewal does not happen automatically — you choose when to renew.',
                    ],
                    [
                        'q' => 'Will my membership number change when I renew?',
                        'a' => 'No. Your membership number is issued once, when your membership is first activated, and stays the same for as long as you remain a member — including across every renewal.',
                    ],
                    [
                        'q' => 'How do I set up my account?',
                        'a' => 'Once your membership is activated, you\'ll receive an email with a one-time link to set your account password. After that, you can sign in any time from the Sign In page.',
                    ],
                    [
                        'q' => 'What is the digital membership card and QR code for?',
                        'a' => 'Once you\'re signed in, your account includes a digital membership card with a QR code. Scanning it takes you to a page confirming your membership is currently active — it never reveals your email, phone or other personal details. The card follows your real membership status: if your membership lapses it will show as not currently valid, and it becomes valid again as soon as you renew.',
                    ],
                    [
                        'q' => 'What happens if my application is rejected?',
                        'a' => 'You\'ll be told if your application isn\'t approved. You\'re welcome to apply again whenever you\'re ready — there\'s no waiting period.',
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
                        <p class="mt-3 text-sm leading-relaxed text-muted-foreground">{{ $faq['a'] }}</p>
                    </details>
                @endforeach
            </div>

            <p class="mt-10 text-center text-sm text-muted-foreground">
                Still have questions? <a href="{{ route('membership.apply') }}" class="font-semibold text-[#CC001F] hover:underline">Start your application</a> and ACI can help along the way.
            </p>
        </div>
    </section>
</x-layouts.public>
