@props(['name', 'label', 'type' => 'text', 'hint' => null, 'required' => true, 'labelKey' => null, 'rows' => 2, 'value' => null])

@php
    $id = str_replace(['[', ']', '.'], '_', $name);
    $hasError = $errors->has($name);
    $describedBy = trim(($hint ? $id.'-hint ' : '').($hasError ? $id.'-error' : ''));
@endphp

<div class="space-y-1.5">
    <label for="{{ $id }}" class="block text-sm font-medium leading-none">
        <span @if ($labelKey) data-label-for="{{ $labelKey }}" @endif>{{ $label }}</span>
    </label>

    @if ($type === 'textarea')
        <textarea
            id="{{ $id }}"
            name="{{ $name }}"
            rows="{{ $rows }}"
            @required($required)
            @if ($hasError) aria-invalid="true" @endif
            @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
            {{ $attributes->class(['field-control']) }}
        >{{ old($name, $value) }}</textarea>
    @else
        <input
            id="{{ $id }}"
            name="{{ $name }}"
            type="{{ $type }}"
            value="{{ old($name, $value) }}"
            @required($required)
            @if ($hasError) aria-invalid="true" @endif
            @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
            {{ $attributes->class(['field-control']) }}
        >
    @endif

    @if ($hint)
        <p id="{{ $id }}-hint" class="text-xs text-muted-foreground">{{ $hint }}</p>
    @endif

    @error($name)
        <p id="{{ $id }}-error" class="text-xs text-destructive">{{ $message }}</p>
    @enderror
</div>
