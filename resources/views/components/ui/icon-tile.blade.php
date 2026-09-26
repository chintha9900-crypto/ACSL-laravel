@props(['size' => 'default'])

@php
    $sizes = [
        'sm' => 'h-9 w-9 rounded-lg',
        'default' => 'h-12 w-12 rounded-xl',
    ];
@endphp

{{-- Icon on a gradient tile — docs/frontend/06 §6 `ui.icon-tile`. --}}
<div {{ $attributes->class(['grid shrink-0 place-items-center bg-gradient-to-br from-[#4D4D4D] to-[#666666] text-white', $sizes[$size] ?? $sizes['default']]) }}>
    {{ $slot }}
</div>
