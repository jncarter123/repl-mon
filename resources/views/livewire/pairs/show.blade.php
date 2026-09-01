@use('App\Support\Duration')

<div class="flex w-full flex-col gap-6" wire:poll.30s>
    <x-page-header :heading="$pair->name" :subheading="$pair->description">
        <x-slot:actions>
            <flux:button icon="arrow-path" wire:click="checkNow" wire:loading.attr="disabled" wire:target="checkNow">
                Check now
            </flux:button>
            <flux:button
                icon="arrows-right-left"
                wire:click="verifyReplication"
                wire:loading.attr="disabled"
                wire:target="verifyReplication"
            >
                Verify replication
            </flux:button>
            <flux:button :href="route('pairs.edit', $pair)" icon="pencil-square" variant="primary" wire:navigate>
                Edit
            </flux:button>
        </x-slot:actions>
    </x-page-header>

    @unless ($pair->enabled)
        <flux:callout color="zinc" icon="pause" heading="This pair is paused">
            <flux:callout.text>Nothing is being checked and no alerts will be sent until you switch it back on.</flux:callout.text>
        </flux:callout>
    @endunless

    @if ($verifyResult)
        <flux:callout
            :color="$verifyResult['color']"
            :icon="$verifyResult['icon']"
            :heading="'Replication — '.$verifyResult['outcome']"
        >
            <flux:callout.text class="break-words">{{ $verifyResult['message'] }}</flux:callout.text>
        </flux:callout>
    @endif

    <flux:card class="space-y-4">
        <div class="flex flex-wrap items-center gap-3">
            <x-status-badge :status="$pair->current_status" size="lg" />
            @if ($pair->status_changed_at)
                <flux:text size="sm">since {{ $pair->status_changed_at->diffForHumans() }}</flux:text>
            @endif
            @if ($pair->alerting)
                <flux:badge color="red" size="sm" icon="envelope">alert sent</flux:badge>
            @endif
        </div>

        @if ($pair->last_message)
            <flux:text>{{ $pair->last_message }}</flux:text>
        @endif

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-stat-tile
                label="Measured lag"
                :value="Duration::humanize($pair->last_lag_seconds)"
                :tone="$pair->current_status->color() === 'green' ? 'green' : $pair->current_status->color()"
                :hint="'threshold ' . $pair->lag_threshold_seconds . 's'"
            />
            <x-stat-tile
                label="Last checked"
                :value="$pair->last_checked_at?->diffForHumans() ?? 'never'"
                :hint="$pair->last_checked_at?->toDayDateTimeString()"
            />
            <x-stat-tile
                label="Last healthy"
                :value="$pair->last_ok_at?->diffForHumans() ?? 'never'"
                :hint="$pair->failing_since ? 'failing since ' . $pair->failing_since->diffForHumans() : null"
            />
            <x-stat-tile
                label="Consecutive failures"
                :value="$pair->consecutive_failures"
                :tone="$pair->consecutive_failures > 0 ? 'red' : 'zinc'"
                :hint="'alerts after ' . $pair->failures_before_alert"
            />
        </div>

        <flux:separator variant="subtle" />

        <div class="grid gap-4 text-sm sm:grid-cols-2">
            <div>
                <flux:text size="sm" class="uppercase tracking-wide">Primary</flux:text>
                <div class="font-mono">{{ $pair->primaryLabel() }}</div>
                <flux:text size="sm">user {{ $pair->primary_username }}{{ $pair->primary_use_tls ? ' · TLS' : '' }}</flux:text>
            </div>
            <div>
                <flux:text size="sm" class="uppercase tracking-wide">Replica</flux:text>
                <div class="font-mono">{{ $pair->replicaLabel() }}</div>
                <flux:text size="sm">user {{ $pair->replica_username }}{{ $pair->replica_use_tls ? ' · TLS' : '' }}</flux:text>
            </div>
        </div>
    </flux:card>

    <flux:card class="space-y-5">
        <div>
            <flux:heading size="lg">Who gets told</flux:heading>
            <flux:subheading>
                @if ($this->usingGlobalList)
                    This pair has no list of its own, so alerts go to the
                    <flux:link :href="route('recipients.index')" wire:navigate>global recipients</flux:link>.
                    Adding anyone below replaces that, it does not add to it.
                @else
                    This pair has its own list. The global recipients do not receive its alerts.
                @endif
            </flux:subheading>
        </div>

        @if ($this->effectiveRecipients->isEmpty())
            <flux:callout color="red" icon="exclamation-triangle" heading="Nobody would be emailed">
                <flux:callout.text>
                    This pair has no recipients and the global list is empty, so an outage here would be recorded and
                    never sent. Add someone below, or to the global list.
                </flux:callout.text>
            </flux:callout>
        @endif

        <div class="flex flex-wrap gap-2">
            @foreach ($this->effectiveRecipients as $recipient)
                <flux:badge :color="$this->usingGlobalList ? 'zinc' : 'blue'" size="sm" icon="envelope">
                    {{ $recipient->name ? $recipient->name . ' <' . $recipient->email . '>' : $recipient->email }}
                </flux:badge>
            @endforeach
        </div>

        @if ($this->ownRecipients->isNotEmpty())
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Name</flux:table.column>
                    <flux:table.column>Email</flux:table.column>
                    <flux:table.column align="end"></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($this->ownRecipients as $recipient)
                        <flux:table.row :key="$recipient->id">
                            <flux:table.cell>{{ $recipient->name ?? '—' }}</flux:table.cell>
                            <flux:table.cell>{{ $recipient->email }}</flux:table.cell>
                            <flux:table.cell align="end">
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    icon="trash"
                                    wire:click="removeRecipient({{ $recipient->id }})"
                                    wire:confirm="Remove {{ $recipient->email }} from this pair?"
                                />
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif

        <form wire:submit="addRecipient" class="flex flex-wrap items-end gap-3">
            <flux:input wire:model="recipientName" label="Name" placeholder="Optional" class="max-w-48" />
            <flux:input wire:model="recipientEmail" label="Email" type="email" placeholder="oncall@example.com" class="max-w-72" />
            <flux:button type="submit" icon="plus">Add to this pair</flux:button>
        </form>
    </flux:card>

    <div class="grid gap-6 xl:grid-cols-2">
        <div>
            <flux:heading size="lg" class="mb-3">Recent checks</flux:heading>

            @if ($this->checks->isEmpty())
                <flux:callout icon="clock" heading="No checks yet">
                    <flux:callout.text>The scheduler runs every minute, or use “Check now” above.</flux:callout.text>
                </flux:callout>
            @else
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>When</flux:table.column>
                        <flux:table.column>Status</flux:table.column>
                        <flux:table.column align="end">Lag</flux:table.column>
                        <flux:table.column>Threads</flux:table.column>
                        <flux:table.column align="end">Took</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($this->checks as $check)
                            <flux:table.row :key="$check->id">
                                <flux:table.cell title="{{ $check->checked_at->toDayDateTimeString() }}">
                                    {{ $check->checked_at->diffForHumans(short: true) }}
                                </flux:table.cell>
                                <flux:table.cell>
                                    <x-status-badge :status="$check->status" />
                                </flux:table.cell>
                                <flux:table.cell align="end" class="tabular-nums">
                                    {{ Duration::humanize($check->lag_seconds) }}
                                </flux:table.cell>
                                <flux:table.cell class="text-xs">
                                    @if ($check->io_running || $check->sql_running)
                                        IO {{ $check->io_running ?? '?' }} · SQL {{ $check->sql_running ?? '?' }}
                                    @elseif ($check->status_query_error)
                                        <span class="text-zinc-400" title="{{ $check->status_query_error }}">unreadable</span>
                                    @else
                                        <span class="text-zinc-400">—</span>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell align="end" class="tabular-nums text-xs">{{ $check->duration_ms }}ms</flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif
        </div>

        <div>
            <flux:heading size="lg" class="mb-3">Alerts sent</flux:heading>

            @if ($this->alerts->isEmpty())
                <flux:callout icon="envelope" heading="No alerts sent">
                    <flux:callout.text>Nothing has gone wrong with this pair since it was added.</flux:callout.text>
                </flux:callout>
            @else
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>Sent</flux:table.column>
                        <flux:table.column>Kind</flux:table.column>
                        <flux:table.column>Subject</flux:table.column>
                        <flux:table.column>To</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($this->alerts as $alert)
                            <flux:table.row :key="$alert->id">
                                <flux:table.cell title="{{ $alert->sent_at->toDayDateTimeString() }}">
                                    {{ $alert->sent_at->diffForHumans(short: true) }}
                                </flux:table.cell>
                                <flux:table.cell>
                                    <flux:badge :color="$alert->kind->color()" size="sm">{{ $alert->kind->label() }}</flux:badge>
                                </flux:table.cell>
                                <flux:table.cell class="max-w-xs truncate">{{ $alert->subject }}</flux:table.cell>
                                <flux:table.cell class="text-xs">
                                    @if ($alert->delivery_error)
                                        <span class="text-red-600 dark:text-red-400" title="{{ $alert->delivery_error }}">
                                            not delivered
                                        </span>
                                    @else
                                        {{ implode(', ', $alert->recipients) }}
                                    @endif
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif
        </div>
    </div>
</div>
