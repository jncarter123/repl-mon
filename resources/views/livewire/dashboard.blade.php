@use('App\Support\Duration')

<div class="flex w-full flex-col gap-6" wire:poll.20s>
    <x-page-header
        heading="Replication status"
        subheading="Every enabled pair is checked once a minute. Worst first."
    >
        <x-slot:actions>
            <flux:button :href="route('pairs.create')" icon="plus" variant="primary" wire:navigate>
                Add a pair
            </flux:button>
        </x-slot:actions>
    </x-page-header>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat-tile label="Pairs" :value="$this->counts['total']" :hint="$this->counts['disabled'] . ' not being checked'" />
        <x-stat-tile label="Healthy" :value="$this->counts['ok']" tone="green" />
        <x-stat-tile
            label="Problems"
            :value="$this->counts['problem']"
            :tone="$this->counts['problem'] > 0 ? 'red' : 'zinc'"
        />
        <x-stat-tile label="Awaiting first check" :value="$this->counts['unknown']" tone="amber" />
    </div>

    @if ($this->pairs->isEmpty())
        <flux:callout icon="circle-stack" heading="No pairs configured yet">
            <flux:callout.text>
                Add a primary and its replica, create the heartbeat table, and this page starts filling in a minute later.
            </flux:callout.text>
            <x-slot:actions>
                <flux:button :href="route('pairs.create')" wire:navigate>Add a pair</flux:button>
            </x-slot:actions>
        </flux:callout>
    @else
        <flux:table>
            <flux:table.columns>
                <flux:table.column>Pair</flux:table.column>
                <flux:table.column>Status</flux:table.column>
                <flux:table.column align="end">Lag</flux:table.column>
                <flux:table.column>Last checked</flux:table.column>
                <flux:table.column>Detail</flux:table.column>
                <flux:table.column align="end"></flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->pairs as $pair)
                    <flux:table.row :key="$pair->id">
                        <flux:table.cell>
                            <flux:link :href="route('pairs.show', $pair)" wire:navigate class="font-medium">
                                {{ $pair->name }}
                            </flux:link>
                            <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                {{ $pair->primary_host }} &rarr; {{ $pair->replica_host }}
                            </div>
                        </flux:table.cell>

                        <flux:table.cell>
                            @if ($pair->enabled)
                                <x-status-badge :status="$pair->current_status" />
                            @else
                                <flux:badge color="zinc" size="sm" icon="pause">Paused</flux:badge>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell align="end" class="tabular-nums">
                            {{ Duration::humanize($pair->last_lag_seconds) }}
                            <div class="text-xs text-zinc-500 dark:text-zinc-400">of {{ $pair->lag_threshold_seconds }}s</div>
                        </flux:table.cell>

                        <flux:table.cell>
                            @if ($pair->last_checked_at)
                                <span title="{{ $pair->last_checked_at->toDayDateTimeString() }}">
                                    {{ $pair->last_checked_at->diffForHumans() }}
                                </span>
                            @else
                                <span class="text-zinc-400">never</span>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell class="max-w-md !whitespace-normal text-xs text-zinc-600 dark:text-zinc-400">
                            {{ $pair->last_message }}
                        </flux:table.cell>

                        <flux:table.cell align="end">
                            <flux:button
                                size="sm"
                                variant="ghost"
                                icon="arrow-path"
                                wire:click="checkNow({{ $pair->id }})"
                                wire:loading.attr="disabled"
                                wire:target="checkNow({{ $pair->id }})"
                            >
                                Check now
                            </flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif

    @if ($this->recentAlerts->isNotEmpty())
        <div>
            <flux:heading size="lg" class="mb-3">Recent alerts</flux:heading>

            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Sent</flux:table.column>
                    <flux:table.column>Pair</flux:table.column>
                    <flux:table.column>Kind</flux:table.column>
                    <flux:table.column>Subject</flux:table.column>
                    <flux:table.column>To</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->recentAlerts as $alert)
                        <flux:table.row :key="$alert->id">
                            <flux:table.cell title="{{ $alert->sent_at->toDayDateTimeString() }}">
                                {{ $alert->sent_at->diffForHumans() }}
                            </flux:table.cell>
                            <flux:table.cell>
                                @if ($alert->serverPair)
                                    <flux:link :href="route('pairs.show', $alert->serverPair)" wire:navigate>
                                        {{ $alert->serverPair->name }}
                                    </flux:link>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:badge :color="$alert->kind->color()" size="sm">{{ $alert->kind->label() }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell class="max-w-sm truncate">{{ $alert->subject }}</flux:table.cell>
                            <flux:table.cell>
                                @if ($alert->delivery_error)
                                    <flux:badge color="red" size="sm" icon="exclamation-triangle">not delivered</flux:badge>
                                @else
                                    {{ count($alert->recipients) }} recipient(s)
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>
    @endif

    <flux:card class="space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <flux:heading size="lg">Health endpoint</flux:heading>
                <flux:subheading>
                    Point Icinga — or anything that speaks HTTP — at this. It answers 200 only while every
                    enabled pair is healthy <em>and</em> still being checked, so it catches the one failure
                    this page cannot show you: the monitor itself having stopped.
                </flux:subheading>
            </div>

            @if ($this->health->isConfigured())
                <flux:badge color="green" size="sm" icon="signal">Enabled</flux:badge>
            @else
                <flux:badge color="zinc" size="sm" icon="signal-slash">Off</flux:badge>
            @endif
        </div>

        @if ($this->health->isConfigured())
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:field>
                    <flux:label>URL</flux:label>
                    <flux:input readonly copyable value="{{ $this->health->url }}" />
                    <flux:description>
                        Add <code>?pair=</code> a name or id for one pair on its own, or
                        <code>?format=json</code> for the numbers.
                    </flux:description>
                </flux:field>

                <flux:field>
                    <flux:label>Token</flux:label>
                    <flux:input readonly viewable copyable type="password" value="{{ $this->health->token }}" />
                    <flux:description>
                        Send it as <code>X-Health-Token</code>, <code>Authorization: Bearer</code>, or
                        <code>?token=</code>. Without it the URL answers 404.
                    </flux:description>
                </flux:field>
            </div>

            <div>
                <flux:text size="sm" class="mb-2">A check command to paste, with the token in <code>$TOKEN</code>:</flux:text>
                <pre class="overflow-x-auto rounded-lg bg-zinc-50 p-3 font-mono text-xs text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">{{ $this->health->checkCommand() }}</pre>
            </div>
        @else
            <flux:callout icon="key" heading="No token is set, so the endpoint answers 404">
                <flux:callout.text>
                    It names your pairs and their state, so it stays switched off until you give it a secret.
                    Put one in <code>docker.env</code> (or <code>.env</code>) and restart:
                    <span class="mt-2 block font-mono text-xs">REPL_HEALTH_TOKEN=$(openssl rand -hex 24)</span>
                </flux:callout.text>
            </flux:callout>
        @endif
    </flux:card>
</div>
