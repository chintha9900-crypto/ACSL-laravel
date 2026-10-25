{{--
    E-Shop catalogue sidebar filters — shared between the desktop <aside>
    and the mobile/tablet <details> disclosure in eshop/index.blade.php, so
    the two can never drift apart. Included (not a controller-fed
    component), so it inherits `$categories`/`$selectedCategory` from the
    parent view exactly as already passed by EshopController::index() — no
    controller change for this visual redesign.

    Category links are real (`?category=slug`, unchanged from before this
    redesign) only when at least one `ProductCategory` exists. The database
    currently has none, so the three "Category N" placeholders below are
    shown instead, styled as inert (non-clickable) examples of the layout —
    never a real link to a non-existent category.

    The "Access" options are real links (`?access=members`). Eligibility is
    decided only by EshopController; these links just carry the choice, and
    the category links keep it.
--}}
@php($hasRealCategories = $categories->isNotEmpty())
@php($categoryOptions = $hasRealCategories
    ? $categories->pluck('name', 'slug')->all()
    : ['category-1' => 'Category 1', 'category-2' => 'Category 2', 'category-3' => 'Category 3'])

<div>
    <h2 class="font-display text-sm font-bold uppercase tracking-wide text-primary">Categories</h2>
    <ul class="mt-3 space-y-1">
        <li>
            @php($allActive = ($selectedCategory?->slug ?? null) === null)
            <a href="{{ route('eshop.index', array_filter(['access' => ($membersOnly ?? false) ? 'members' : null])) }}"
                @if ($allActive) aria-current="true" @endif
                @class([
                    'block rounded-md px-3 py-2 text-sm font-medium transition-colors',
                    'bg-primary text-primary-foreground' => $allActive,
                    'text-foreground/75 hover:bg-muted hover:text-primary' => ! $allActive,
                ])>All products</a>
        </li>
        @foreach ($categoryOptions as $slug => $label)
            <li>
                @if ($hasRealCategories)
                    @php($active = ($selectedCategory?->slug ?? null) === $slug)
                    <a href="{{ route('eshop.index', array_filter(['category' => $slug, 'access' => ($membersOnly ?? false) ? 'members' : null])) }}"
                        @if ($active) aria-current="true" @endif
                        @class([
                            'block rounded-md px-3 py-2 text-sm font-medium transition-colors',
                            'bg-primary text-primary-foreground' => $active,
                            'text-foreground/75 hover:bg-muted hover:text-primary' => ! $active,
                        ])>{{ $label }}</a>
                @else
                    <span class="block cursor-default rounded-md px-3 py-2 text-sm font-medium text-foreground/50" aria-disabled="true">{{ $label }}</span>
                @endif
            </li>
        @endforeach
    </ul>
    @unless ($hasRealCategories)
        <p class="mt-2 text-xs text-muted-foreground">Example categories — add real ones in Admin → Product categories.</p>
    @endunless
</div>

<div class="mt-8">
    <h2 class="font-display text-sm font-bold uppercase tracking-wide text-primary">Access</h2>
    {{-- "Members only" filters the catalogue for eligible members; for anyone
         else the server answers with a sign-in prompt (EshopController). --}}
    <ul class="mt-3 space-y-1">
        @foreach ([null => 'Products', 'members' => 'Members only products'] as $accessValue => $accessLabel)
            @php($accessActive = ($accessValue === 'members') === (($membersOnly ?? false) || ($membersPrompt ?? false)))
            <li>
                <a href="{{ route('eshop.index', array_filter(['access' => $accessValue, 'category' => $selectedCategory?->slug])) }}"
                    @if ($accessActive) aria-current="true" @endif
                    @if ($accessValue === 'members') data-members-filter @endif
                    @class([
                        'flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium transition-colors',
                        'bg-primary text-primary-foreground' => $accessActive,
                        'text-foreground/75 hover:bg-muted hover:text-primary' => ! $accessActive,
                    ])>
                    @if ($accessValue === 'members')
                        <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    @endif
                    {{ $accessLabel }}
                </a>
            </li>
        @endforeach
    </ul>
</div>
