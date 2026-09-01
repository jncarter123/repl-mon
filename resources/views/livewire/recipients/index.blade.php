<div class="flex w-full flex-col gap-6">
    <x-page-header
        heading="Alert recipients"
        subheading="The default list. A pair with recipients of its own uses those instead of these."
    />

    <div class="grid gap-6 lg:grid-cols-3">
        <flux:card class="space-y-5 lg:col-span-2">
            <flux:heading size="lg">Global list</flux:heading>

            @if ($this->recipients->where('enabled', true)->isEmpty())
                <flux:callout color="red" icon="exclamation-triangle" heading="No active global recipients">
                    <flux:callout.text>
                        Any pair without its own list would have an outage recorded and never sent to anyone.
                    </flux:callout.text>
                </flux:callout>
            @endif

            @if ($this->recipients->isNotEmpty())
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>Name</flux:table.column>
                        <flux:table.column>Email</flux:table.column>
                        <flux:table.column>Receiving</flux:table.column>
                        <flux:table.column align="end"></flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($this->recipients as $recipient)
                            <flux:table.row :key="$recipient->id">
                                <flux:table.cell>{{ $recipient->name ?? '—' }}</flux:table.cell>
                                <flux:table.cell>{{ $recipient->email }}</flux:table.cell>
                                <flux:table.cell>
                                    <flux:switch :checked="$recipient->enabled" wire:click="toggle({{ $recipient->id }})" />
                                </flux:table.cell>
                                <flux:table.cell align="end">
                                    <flux:button
                                        size="sm"
                                        variant="ghost"
                                        icon="trash"
                                        wire:click="remove({{ $recipient->id }})"
                                        wire:confirm="Remove {{ $recipient->email }} from the global list?"
                                    />
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif

            <form wire:submit="add" class="flex flex-wrap items-end gap-3">
                <flux:input wire:model="name" label="Name" placeholder="Optional" class="max-w-48" />
                <flux:input wire:model="email" label="Email" type="email" placeholder="oncall@example.com" class="max-w-72" />
                <flux:button type="submit" icon="plus" variant="primary">Add</flux:button>
            </form>
        </flux:card>

        <flux:card class="space-y-4">
            <div>
                <flux:heading size="lg">Pairs with their own list</flux:heading>
                <flux:subheading>These do not fall back to the global recipients.</flux:subheading>
            </div>

            @if ($this->pairsWithOwnLists->isEmpty())
                <flux:text size="sm">Every pair uses the global list.</flux:text>
            @else
                <div class="flex flex-col gap-2">
                    @foreach ($this->pairsWithOwnLists as $pair)
                        <flux:link :href="route('pairs.show', $pair)" wire:navigate>{{ $pair->name }}</flux:link>
                    @endforeach
                </div>
            @endif
        </flux:card>
    </div>
</div>
