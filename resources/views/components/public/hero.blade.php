@props(['badge' => null, 'lead', 'breadcrumbTitle' => null])
{{--
    Grey (#686868) page-introduction strip below the header on every public
    inner page: title, lead and the breadcrumb line, all white (5.6:1).
    `breadcrumbTitle` names the current page in the breadcrumbs on detail
    pages (e.g. a job's title). Vertical padding is 25px less than the
    original 80px / 96px (md).

    The badge is no longer shown: pages may still pass a `badge` slot, which is
    ignored. The title uses the paragraph's font and letter-spacing (only
    larger and bold), and any emphasis `<span>` in it — which pages colour red —
    is plain white, with no underline.
--}}
<section class="bg-[#686868] text-white" data-page-intro>
    <div class="site-container py-[55px] text-center md:py-[71px]">
        <h1 class="font-sans text-4xl font-bold tracking-normal text-white md:text-6xl [&_span]:text-white">{{ $slot }}</h1>
        <p class="mx-auto mt-5 max-w-2xl">{{ $lead }}</p>
        <x-public.breadcrumbs :current="$breadcrumbTitle" centered class="mt-5" />
    </div>
</section>
