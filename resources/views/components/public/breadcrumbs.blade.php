@props(['current' => null, 'centered' => false])
@use('App\Support\Breadcrumbs')
{{--
    Breadcrumb line for the grey page-introduction strip (`x-public.hero` and
    the article headers). The trail comes from the current route name
    (`App\Support\Breadcrumbs`); `current` replaces the last label with a
    record's real title on detail pages. Ancestors are links; the current page
    is plain text. Renders nothing for a route without an entry.
--}}
@php($trail = Breadcrumbs::trail(request()->route()?->getName(), $current))
@if ($trail !== [])
    <nav aria-label="Breadcrumb" data-breadcrumbs {{ $attributes->class(['text-sm text-white']) }}>
        <ol @class(['flex flex-wrap items-center gap-y-1', 'justify-center' => $centered])>
            @foreach ($trail as $crumb)
                <li class="flex min-w-0 items-center">
                    @unless ($loop->first)
                        <span class="mx-2" aria-hidden="true">/</span>
                    @endunless
                    @if ($crumb['url'] !== null)
                        <a href="{{ $crumb['url'] }}" class="underline decoration-white/60 underline-offset-2 hover:decoration-white">{{ $crumb['label'] }}</a>
                    @elseif ($loop->last)
                        <span aria-current="page" class="break-words">{{ $crumb['label'] }}</span>
                    @else
                        <span>{{ $crumb['label'] }}</span>
                    @endif
                </li>
            @endforeach
        </ol>
    </nav>
@endif
