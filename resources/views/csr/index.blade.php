@use('Illuminate\Support\Facades\Storage')
{{--
    CSR listing — one column, each project shown one after another with its
    image and content below it. With 5 or fewer published projects, each
    project's full content is shown directly here (no View More); once there
    are more than 5, each card shows only the project's first 10 lines
    (`CsrProject::previewLines()`, computed in `CsrController::index()`) with
    a "View More" link to its own page (`csr.show`).
--}}
<x-layouts.public title="CSR">
    <x-public.hero>
        <x-slot:badge>Corporate Social Responsibility</x-slot:badge>
        <x-slot:lead>
            How Aviation Club International gives back to the communities and environment aviation touches.
        </x-slot:lead>
        Our <span class="text-[#CC001F]">CSR</span> projects.
    </x-public.hero>

    <section class="border-t border-border">
        <div class="container mx-auto max-w-3xl px-4 py-16 md:py-20 lg:px-8">
            @if ($projects->isEmpty())
                <p class="rounded-lg border border-border bg-muted px-4 py-8 text-center text-sm text-muted-foreground" role="status">
                    No CSR projects to show yet. Please check back soon.
                </p>
            @else
                <div class="flex flex-col gap-10">
                    @foreach ($projects as $project)
                        <article class="ui-card overflow-hidden">
                            <div class="aspect-[16/9] w-full overflow-hidden bg-[#4D4D4D]">
                                @if ($project->image_path)
                                    <img src="{{ Storage::disk('public')->url($project->image_path) }}" alt="{{ $project->title }}" class="h-full w-full object-cover">
                                @else
                                    <div class="grid h-full w-full place-items-center">
                                        <svg class="h-10 w-10 text-white/40" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 4h16v16H4z"/><path d="M4 9h16M9 4v16"/></svg>
                                    </div>
                                @endif
                            </div>

                            <div class="p-6 md:p-7">
                                <h2 class="font-display text-2xl font-bold text-primary">
                                    <a href="{{ route('csr.show', $project->slug) }}" class="hover:text-[#CC001F]">{{ $project->title }}</a>
                                </h2>

                                @if ($showPreview)
                                    <p class="mt-4 whitespace-pre-line text-sm leading-relaxed text-muted-foreground">{{ $project->previewLines() }}</p>

                                    <div class="mt-5">
                                        <a href="{{ route('csr.show', $project->slug) }}" class="btn btn-lg btn-brand">View More</a>
                                    </div>
                                @else
                                    <div class="mt-4 text-sm leading-relaxed text-muted-foreground [&_a]:font-semibold [&_a]:text-[#CC001F] [&_a]:underline [&_li]:mb-1 [&_ol]:mb-4 [&_ol]:list-decimal [&_ol]:pl-5 [&_p]:mb-4 [&_ul]:mb-4 [&_ul]:list-disc [&_ul]:pl-5">
                                        {!! $project->content !!}
                                    </div>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        </div>
    </section>
</x-layouts.public>
