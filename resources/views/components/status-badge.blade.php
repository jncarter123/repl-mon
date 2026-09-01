@props(['status', 'size' => 'sm'])

<flux:badge :color="$status->color()" :icon="$status->icon()" :size="$size" {{ $attributes }}>
    {{ $status->label() }}
</flux:badge>
