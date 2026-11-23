{{--
    Education — general guidance on aviation learning pathways. No training
    providers, courses, qualifications or external links are named: none are
    verified yet, so the resources panel shows a "coming soon" message and
    links only to existing pages on this site.
--}}
@php($pathways = [
    'Flight Training' => 'Learning to fly, from first lessons through to the training required for a professional flying career. Flight training combines ground study with supervised time in the aircraft.',
    'Aircraft Engineering and Maintenance' => 'Keeping aircraft safe and airworthy. Engineering and maintenance study covers aircraft systems, inspection and repair, with a strong focus on safety and accuracy.',
    'Cabin Crew Training' => 'Preparing for a role in the cabin, where safety, emergency procedures and passenger care come together on every flight.',
    'Aviation Operations and Airport Management' => 'The work that keeps flights moving on the ground and behind the scenes, including flight operations, airport operations and aviation management.',
    'Other Aviation Learning Opportunities' => 'Aviation also includes air traffic control, aerospace and aeronautical engineering, aviation safety, cargo and many more specialist areas, each with its own path of study.',
])
<x-layouts.public title="Education">
    <x-public.hero>
        <x-slot:badge>Education</x-slot:badge>
        <x-slot:lead>
            Aviation depends on people who keep learning. Good education and continuous professional development build the knowledge, skills and safety culture the industry relies on, whether you are just starting out or growing an established career.
        </x-slot:lead>
        Education
    </x-public.hero>

    <section class="border-t border-border">
        <div class="site-container grid gap-10 py-16 md:grid-cols-[2fr_1fr] md:py-20 lg:grid-cols-[7fr_3fr] lg:gap-12">
            <div class="min-w-0 space-y-6">
                <div>
                    <h2 class="font-display text-2xl font-bold text-primary">Aviation education and training pathways</h2>
                    <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                        There are many ways into aviation. These are some of the main areas of study and training.
                    </p>
                </div>

                @foreach ($pathways as $title => $description)
                    <div class="ui-card p-6 md:p-8">
                        <h3 class="font-display text-xl font-bold text-primary">{{ $title }}</h3>
                        <p class="mt-3 text-sm leading-relaxed text-muted-foreground">{{ $description }}</p>
                    </div>
                @endforeach
            </div>

            <aside class="space-y-6">
                <div class="ui-card p-6">
                    <h2 class="font-display text-lg font-bold text-primary">Education resources</h2>
                    <p class="mt-3 text-sm leading-relaxed text-muted-foreground">Resources coming soon.</p>
                </div>

                <div class="ui-card p-6">
                    <h2 class="font-display text-lg font-bold text-primary">Considering an aviation career?</h2>
                    <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                        Explore the different areas of aviation, talk to people already working in the industry, and choose a pathway that matches your interests. Students studying an aviation-related subject can join the club as Aviation Student members.
                    </p>
                    <a href="{{ route('membership.benefits') }}" class="mt-4 inline-block text-sm font-semibold text-[#CC001F] hover:underline">View membership types</a>
                </div>

                <div class="ui-card p-6">
                    <h2 class="font-display text-lg font-bold text-primary">Training institutions</h2>
                    <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                        Information about aviation training institutions is coming soon. Schools, colleges and universities can support aviation clubs through School &amp; University Club Membership.
                    </p>
                </div>

                <div class="ui-card p-6">
                    <h2 class="font-display text-lg font-bold text-primary">Join the community</h2>
                    <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
                        Become a member to connect with people who work, study or take part in aviation.
                    </p>
                    <div class="mt-4 flex flex-wrap gap-2">
                        <a href="{{ route('membership.apply') }}" class="btn btn-sm btn-brand">Become a Member</a>
                        <a href="{{ route('contact') }}" class="btn btn-sm btn-ghost border border-border">Contact Us</a>
                    </div>
                </div>
            </aside>
        </div>
    </section>
</x-layouts.public>
