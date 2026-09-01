<div class="flex w-full flex-col gap-6">
    <x-page-header
        heading="Health endpoint"
        subheading="How something outside watches this monitor. Email is the way out that fails quietly; this is the one that cannot."
    >
        <x-slot:actions>
            @if ($this->tokens->isNotEmpty() || $this->environmentToken)
                <flux:badge color="green" size="lg" icon="signal">Enabled</flux:badge>
            @else
                <flux:badge color="zinc" size="lg" icon="signal-slash">Off</flux:badge>
            @endif
        </x-slot:actions>
    </x-page-header>

    <flux:card class="space-y-5">
        <flux:text>
            Point Icinga — or anything that speaks HTTP — at this. It answers 200 only while every enabled
            pair is healthy <em>and</em> still being checked, so it catches the one failure the dashboard
            cannot show you: the monitor itself having stopped.
        </flux:text>

        <flux:field>
            <flux:label>URL</flux:label>
            <flux:input readonly copyable value="{{ $this->health->url }}" />
            <flux:description>
                Add <code>?pair=</code> a name or id for one pair on its own, or <code>?format=json</code>
                for the numbers. Send the token as <code>X-Health-Token</code>,
                <code>Authorization: Bearer</code>, or <code>?token=</code>.
            </flux:description>
        </flux:field>

        @if ($this->tokens->isEmpty() && ! $this->environmentToken)
            <flux:callout icon="key" heading="No token exists, so the URL answers 404">
                <flux:callout.text>
                    It names your pairs and their state, so it stays switched off until there is a secret to
                    ask for. Generate one below — it takes effect immediately, with nothing to restart.
                </flux:callout.text>
            </flux:callout>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Name</flux:table.column>
                    <flux:table.column>Token</flux:table.column>
                    <flux:table.column>Created</flux:table.column>
                    <flux:table.column>Last polled</flux:table.column>
                    <flux:table.column align="end"></flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @if ($this->environmentToken)
                        <flux:table.row>
                            <flux:table.cell>
                                REPL_HEALTH_TOKEN
                                <div class="text-xs text-zinc-500 dark:text-zinc-400">from the environment</div>
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:input readonly viewable copyable type="password" size="sm" value="{{ $this->environmentToken }}" />
                            </flux:table.cell>
                            <flux:table.cell class="text-zinc-400">&mdash;</flux:table.cell>
                            <flux:table.cell class="text-zinc-400">&mdash;</flux:table.cell>
                            <flux:table.cell align="end">
                                <flux:tooltip content="Set in docker.env; this app does not write to its own environment.">
                                    <flux:badge color="zinc" size="sm">not editable here</flux:badge>
                                </flux:tooltip>
                            </flux:table.cell>
                        </flux:table.row>
                    @endif

                    @foreach ($this->tokens as $token)
                        <flux:table.row :key="$token->id">
                            <flux:table.cell class="font-medium">{{ $token->name }}</flux:table.cell>

                            <flux:table.cell>
                                <flux:input readonly viewable copyable type="password" size="sm" value="{{ $token->token }}" />
                            </flux:table.cell>

                            <flux:table.cell title="{{ $token->created_at->toDayDateTimeString() }}">
                                {{ $token->created_at->diffForHumans() }}
                            </flux:table.cell>

                            <flux:table.cell>
                                @if ($token->last_used_at)
                                    <span title="{{ $token->last_used_at->toDayDateTimeString() }}">
                                        {{ $token->last_used_at->diffForHumans() }}
                                    </span>
                                @else
                                    <flux:badge color="amber" size="sm">never polled</flux:badge>
                                @endif
                            </flux:table.cell>

                            <flux:table.cell align="end">
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    icon="trash"
                                    wire:click="revokeToken({{ $token->id }})"
                                    wire:confirm="Delete “{{ $token->name }}”? Anything still using it starts getting 401s."
                                >
                                    Delete
                                </flux:button>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif

        <div class="flex flex-wrap items-end gap-3">
            <flux:input
                wire:model="tokenName"
                label="Generate a token"
                placeholder="icinga-master"
                description:trailing="What it is for, so you know which one to delete later."
                class="max-w-xs"
            />
            <flux:button wire:click="generateToken" icon="plus" variant="primary" class="mb-6">
                Generate
            </flux:button>
        </div>

        <div>
            <flux:text size="sm" class="mb-2">A check command to paste, with the token in <code>$TOKEN</code>:</flux:text>
            <pre class="overflow-x-auto rounded-lg bg-zinc-50 p-3 font-mono text-xs text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">{{ $this->health->checkCommand() }}</pre>
        </div>
    </flux:card>
</div>
