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

    The "Access" checkboxes are a visual preview only, left unwired to any
    filtering logic on purpose: this task is a template for the layout, not
    a new product-filtering feature, so nothing here reads or writes a
    request parameter.
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
            <a href="{{ route('eshop.index') }}"
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
                    <a href="{{ route('eshop.index', ['category' => $slug]) }}"
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
    <div class="mt-3 space-y-2 text-sm text-foreground/80">
        <label class="flex items-center gap-2">
            <input type="checkbox" class="h-4 w-4 rounded border-input accent-[#CC001F]">
            <span>All Visitors</span>
        </label>
        <label class="flex items-center gap-2">
            <input type="checkbox" class="h-4 w-4 rounded border-input accent-[#CC001F]">
            <span>Members Only</span>
        </label>
    </div>
</div>
