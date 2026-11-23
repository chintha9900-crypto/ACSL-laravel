{{-- The three static career cards beside the job board (25% on desktop, below it on smaller screens). --}}
<aside class="min-w-0 space-y-6" aria-label="Career resources">
    <div class="ui-card p-6">
        <h2 class="font-display text-lg font-bold text-primary">Career Guidance</h2>
        <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
            There are many routes into aviation, from flight training and engineering to cabin crew, operations and management. Learn what each pathway involves and the qualifications and experience it needs, then explore the education and training that can get you there.
        </p>
        <a href="{{ route('education') }}" class="mt-4 inline-block text-sm font-semibold text-[#CC001F] hover:underline">Explore education pathways</a>
    </div>

    <div class="ui-card p-6">
        <h2 class="font-display text-lg font-bold text-primary">Career Development Resources</h2>
        <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
            Keep building your skills, progress in your career and connect with others in the industry. Career development opportunities are among the benefits of club membership.
        </p>
        <a href="{{ route('membership.benefits') }}" class="mt-4 inline-block text-sm font-semibold text-[#CC001F] hover:underline">View membership types</a>
    </div>

    <div class="ui-card p-6">
        <h2 class="font-display text-lg font-bold text-primary">For Employers and Aviation Organisations</h2>
        <p class="mt-3 text-sm leading-relaxed text-muted-foreground">
            Recruiting for an aviation role? Share your vacancies through Aviation Club International to reach aviation professionals and people preparing for an aviation career. Get in touch with our team to find out more.
        </p>
        <a href="{{ route('contact') }}" class="btn btn-sm btn-brand mt-4">Contact Us</a>
    </div>
</aside>
