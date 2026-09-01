@props(['label', 'value', 'tone' => 'zinc', 'hint' => null])

@php
$tones = [
    'zinc' => 'text-zinc-900 dark:text-white',
    'green' => 'text-green-600 dark:text-green-400',
    'red' => 'text-red-600 dark:text-red-400',
    'amber' => 'text-amber-600 dark:text-amber-400',
];
@endphp

<flux:card class="!p-4">
    <flux:text size="sm" class="uppercase tracking-wide">{{ $label }}</flux:text>
    <div class="mt-1 text-3xl font-semibold tabular-nums {{ $tones[$tone] ?? $tones['zinc'] }}">{{ $value }}</div>
    @if ($hint)
        <flux:text size="sm" class="mt-1">{{ $hint }}</flux:text>
    @endif
</flux:card>
