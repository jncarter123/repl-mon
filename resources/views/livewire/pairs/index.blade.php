<div class="flex w-full flex-col gap-6">
    <x-page-header
        heading="Server pairs"
        subheading="Each pair is one primary and the replica that is supposed to be keeping up with it."
    >
        <x-slot:actions>
            <flux:button :href="route('pairs.create')" icon="plus" variant="primary" wire:navigate>
                Add a pair
            </flux:button>
        </x-slot:actions>
    </x-page-header>

    <flux:input
        wire:model.live.debounce.300ms="search"
        icon="magnifying-glass"
        placeholder="Filter by name or host"
        class="max-w-sm"
        clearable
    />

    @if ($this->pairs->isEmpty())
        <flux:callout icon="circle-stack" heading="Nothing here yet">
            <flux:callout.text>
                {{ $search === '' ? 'Add your first primary and replica to start monitoring.' : 'No pair matches that filter.' }}
            </flux:callout.text>
        </flux:callout>
    @else
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Name</flux:table.column>
                <flux:table.column>Primary</flux:table.column>
                <flux:table.column>Replica</flux:table.column>
                <flux:table.column align="end">Threshold</flux:table.column>
                <flux:table.column>Recipients</flux:table.column>
                <flux:table.column>Checked</flux:table.column>
                <flux:table.column align="end"></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->pairs as $pair)
                    <flux:table.row :key="$pair->id">
                        <flux:table.cell>
                            <flux:link :href="route('pairs.show', $pair)" wire:navigate class="font-medium">
                                {{ $pair->name }}
                            </flux:link>
                            @if ($pair->description)
                                <div class="max-w-xs truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $pair->description }}</div>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell class="font-mono text-xs">{{ $pair->primaryLabel() }}</flux:table.cell>
                        <flux:table.cell class="font-mono text-xs">{{ $pair->replicaLabel() }}</flux:table.cell>
                        <flux:table.cell align="end" class="tabular-nums">{{ $pair->lag_threshold_seconds }}s</flux:table.cell>

                        <flux:table.cell>
                            @if ($pair->recipients_count > 0)
                                {{ $pair->recipients_count }} of its own
                            @else
                                <span class="text-zinc-500 dark:text-zinc-400">global list</span>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell>
                            <flux:switch
                                :checked="$pair->enabled"
                                wire:click="toggle({{ $pair->id }})"
                                label="{{ $pair->enabled ? 'Checking' : 'Paused' }}"
                            />
                        </flux:table.cell>

                        <flux:table.cell align="end">
                            <flux:dropdown position="bottom" align="end">
                                <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" inset="top bottom" />

                                <flux:menu>
                                    <flux:menu.item :href="route('pairs.show', $pair)" icon="eye" wire:navigate>Open</flux:menu.item>
                                    <flux:menu.item :href="route('pairs.edit', $pair)" icon="pencil-square" wire:navigate>Edit</flux:menu.item>
                                    <flux:menu.separator />
                                    <flux:modal.trigger name="delete-pair-{{ $pair->id }}">
                                        <flux:menu.item icon="trash" variant="danger">Delete</flux:menu.item>
                                    </flux:modal.trigger>
                                </flux:menu>
                            </flux:dropdown>

                            <flux:modal name="delete-pair-{{ $pair->id }}" class="min-w-[24rem]">
                                <div class="space-y-6">
                                    <div>
                                        <flux:heading size="lg">Delete {{ $pair->name }}?</flux:heading>
                                        <flux:text class="mt-2">
                                            Its check history, alert history and its own recipient list go with it.
                                            The heartbeat row on the primary is left where it is.
                                        </flux:text>
                                    </div>

                                    <div class="flex justify-end gap-2">
                                        <flux:modal.close>
                                            <flux:button variant="ghost">Cancel</flux:button>
                                        </flux:modal.close>
                                        <flux:button variant="danger" wire:click="delete({{ $pair->id }})">Delete</flux:button>
                                    </div>
                                </div>
                            </flux:modal>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif
</div>
