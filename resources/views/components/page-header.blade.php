@props(['heading', 'subheading' => null])

<div class="flex flex-wrap items-start justify-between gap-4">
    <div>
        <flux:heading size="xl" level="1">{{ $heading }}</flux:heading>
        @if ($subheading)
            <flux:subheading class="mt-1">{{ $subheading }}</flux:subheading>
        @endif
    </div>

    @if (isset($actions))
        <div class="flex items-center gap-2">{{ $actions }}</div>
    @endif
</div>
