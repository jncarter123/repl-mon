<form wire:submit="save" class="flex w-full flex-col gap-6">
    <x-page-header
        :heading="$this->title()"
        subheading="Credentials are encrypted at rest and never sent back to the browser."
    >
        <x-slot:actions>
            <flux:button :href="route('pairs.index')" variant="ghost" wire:navigate>Cancel</flux:button>
            <flux:button type="submit" variant="primary" icon="check">Save pair</flux:button>
        </x-slot:actions>
    </x-page-header>

    <flux:card class="space-y-6">
        <flux:heading size="lg">Identity</flux:heading>

        <div class="grid gap-4 md:grid-cols-2">
            <flux:input wire:model="form.name" label="Name" placeholder="prod-orders → replica-1" required />
            <flux:field variant="inline">
                <flux:switch wire:model="form.enabled" />
                <flux:label>Check this pair every minute</flux:label>
                <flux:description>Turn it off during planned maintenance instead of deleting it.</flux:description>
            </flux:field>
        </div>

        <flux:textarea wire:model="form.description" label="Notes" rows="2" placeholder="Anything the person woken up at 3am should know." />
    </flux:card>

    <div class="grid gap-6 lg:grid-cols-2">
        @foreach ([['primary', 'Primary', 'The server the heartbeat is written to.'], ['replica', 'Replica', 'The server the heartbeat has to reach.']] as [$side, $label, $blurb])
            <flux:card class="space-y-5">
                <div>
                    <flux:heading size="lg">{{ $label }}</flux:heading>
                    <flux:subheading>{{ $blurb }}</flux:subheading>
                </div>

                <div class="grid grid-cols-3 gap-4">
                    <flux:input class="col-span-2" wire:model="form.{{ $side }}_host" label="Host" placeholder="10.0.0.10" required />
                    <flux:input wire:model="form.{{ $side }}_port" label="Port" type="number" required />
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    <flux:input wire:model="form.{{ $side }}_username" label="Username" required />
                    <flux:input wire:model="form.{{ $side }}_database" label="Database" placeholder="repl_monitor" required />
                </div>

                <flux:input
                    wire:model="form.{{ $side }}_password"
                    label="Password"
                    type="password"
                    viewable
                    :disabled="$form->{$side.'_no_password'}"
                    :placeholder="$form->pair ? 'Leave blank to keep the stored password' : ''"
                />

                <div class="flex flex-wrap items-center gap-6">
                    <flux:field variant="inline">
                        <flux:checkbox wire:model.live="form.{{ $side }}_no_password" />
                        <flux:label>No password</flux:label>
                    </flux:field>

                    <flux:field variant="inline">
                        <flux:checkbox wire:model="form.{{ $side }}_use_tls" />
                        <flux:label>Connect over TLS</flux:label>
                    </flux:field>
                </div>

                <flux:separator variant="subtle" />

                <div class="flex items-center gap-3">
                    <flux:button
                        size="sm"
                        icon="beaker"
                        wire:click="test('{{ $side }}')"
                        wire:loading.attr="disabled"
                        wire:target="test('{{ $side }}')"
                    >
                        Test connection
                    </flux:button>
                    <flux:text size="sm" wire:loading wire:target="test('{{ $side }}')">Connecting…</flux:text>
                </div>

                @if ($result = $testResults[$side] ?? null)
                    <flux:callout
                        :color="$result['ok'] ? 'green' : 'red'"
                        :icon="$result['ok'] ? 'check-circle' : 'x-circle'"
                        :heading="$result['ok'] ? 'Connected' : 'Could not connect'"
                    >
                        <flux:callout.text class="break-words">{{ $result['message'] }}</flux:callout.text>

                        @if ($result['ok'])
                            <flux:callout.text class="mt-2">
                                Heartbeat table:
                                <strong>{{ $result['heartbeat_table'] ? 'present' : 'missing' }}</strong>
                                @if ($result['status_message'])
                                    <br>{{ $result['status_message'] }}
                                @endif
                            </flux:callout.text>
                        @endif
                    </flux:callout>
                @endif
            </flux:card>
        @endforeach
    </div>

    <flux:card class="space-y-6">
        <div>
            <flux:heading size="lg">Heartbeat</flux:heading>
            <flux:subheading>
                One row per pair, upserted on the primary and read back off the replica. Both timestamps come from
                this host's clock, so the two servers' clocks never enter the measurement.
            </flux:subheading>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <flux:input
                wire:model="form.heartbeat_table"
                label="Heartbeat table"
                description="Lives in the database named above, and must be inside replication."
                required
            />

            <div class="flex items-end">
                <flux:button
                    icon="circle-stack"
                    wire:click="installHeartbeat"
                    wire:loading.attr="disabled"
                    wire:target="installHeartbeat"
                >
                    Create it on the primary
                </flux:button>
            </div>
        </div>
    </flux:card>

    <flux:card class="space-y-6">
        <div>
            <flux:heading size="lg">Alerting</flux:heading>
            <flux:subheading>Who hears about it is set on the pair's own page, or on the global recipient list.</flux:subheading>
        </div>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <flux:input
                wire:model="form.lag_threshold_seconds"
                label="Lag threshold (seconds)"
                type="number"
                description="Past this, the pair counts as lagging."
                required
            />

            <flux:input
                wire:model="form.failures_before_alert"
                label="Failures before alerting"
                type="number"
                description="Consecutive bad checks. 2 rides out a single slow minute."
                required
            />

            <flux:input
                wire:model="form.realert_after_minutes"
                label="Remind every (minutes)"
                type="number"
                description="0 sends one email per outage and no reminders."
                required
            />

            <flux:input
                wire:model="form.connect_timeout_seconds"
                label="Connect timeout (seconds)"
                type="number"
                description="Keeps one dead server from holding up the other pairs."
                required
            />
        </div>

        <flux:field variant="inline">
            <flux:switch wire:model="form.check_replica_status" />
            <flux:label>Also read SHOW REPLICA STATUS</flux:label>
            <flux:description>
                Catches a stopped IO or SQL thread the moment it stops, rather than waiting for the heartbeat to go
                stale. Needs the REPLICATION CLIENT grant; without it the heartbeat still works on its own.
            </flux:description>
        </flux:field>
    </flux:card>

    <div class="flex justify-end gap-2">
        <flux:button :href="route('pairs.index')" variant="ghost" wire:navigate>Cancel</flux:button>
        <flux:button type="submit" variant="primary" icon="check">Save pair</flux:button>
    </div>
</form>
