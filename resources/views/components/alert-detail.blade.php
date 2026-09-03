@use('App\Support\Duration')

@props(['alert'])

{{--
    The story behind one alert. A row in the alerts table can only ever say
    "you were emailed at 02:15"; this is where somebody who was asleep at the
    time finds out what it was about — especially for a recovery, whose own
    check is the healthy one.
--}}

<flux:modal.trigger :name="'alert-detail-'.$alert->id">
    <flux:button size="sm" variant="ghost" icon="information-circle">Details</flux:button>
</flux:modal.trigger>

<flux:modal :name="'alert-detail-'.$alert->id" class="w-full md:max-w-2xl">
    <div class="space-y-5">
        <div class="space-y-2">
            <flux:heading size="lg">{{ $alert->subject }}</flux:heading>
            <div class="flex flex-wrap items-center gap-2">
                <flux:badge :color="$alert->kind->color()" size="sm">{{ $alert->kind->label() }}</flux:badge>
                <x-status-badge :status="$alert->status" />
                <flux:text size="sm">sent {{ $alert->sent_at->toDayDateTimeString() }}</flux:text>
            </div>
        </div>

        @if ($alert->hasIncident())
            <div>
                <flux:text size="sm" class="uppercase tracking-wide">What happened</flux:text>
                <flux:heading class="mt-1">{{ $alert->incidentHeadline() }}</flux:heading>
            </div>

            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <flux:text size="sm" class="uppercase tracking-wide">Started</flux:text>
                    <div class="text-sm">
                        {{ $alert->incident_truncated ? 'at or before ' : '' }}{{ $alert->incident_started_at->toDayDateTimeString() }}
                        <span class="text-zinc-500 dark:text-zinc-400">
                            ({{ $alert->incident_started_at->diffForHumans() }})
                        </span>
                    </div>
                </div>
                <div>
                    <flux:text size="sm" class="uppercase tracking-wide">Lasted</flux:text>
                    <div class="text-sm tabular-nums">{{ $alert->incidentDuration() }}</div>
                </div>
                <div>
                    <flux:text size="sm" class="uppercase tracking-wide">Failed checks</flux:text>
                    <div class="text-sm tabular-nums">
                        {{ $alert->failed_checks }}
                        @if ($alert->statusBreakdown())
                            <span class="text-zinc-500 dark:text-zinc-400">— {{ $alert->statusBreakdown() }}</span>
                        @endif
                    </div>
                </div>
                <div>
                    <flux:text size="sm" class="uppercase tracking-wide">Worst lag measured</flux:text>
                    <div class="text-sm tabular-nums">{{ Duration::humanize($alert->peak_lag_seconds) }}</div>
                </div>
            </div>

            @if ($alert->first_failure_message)
                <div>
                    <flux:text size="sm" class="uppercase tracking-wide">First failing check said</flux:text>
                    <flux:text class="mt-1 break-words">{{ $alert->first_failure_message }}</flux:text>
                </div>
            @endif

            @if ($alert->incident_truncated)
                <flux:text size="sm">
                    No healthy check sits before this run in the history — it was pruned, or the pair was already
                    failing when it was added — so it began at or before that time and lasted at least that long.
                </flux:text>
            @endif

            @if ($alert->replica_error)
                <flux:callout color="red" icon="exclamation-triangle" heading="The replica reported">
                    <flux:callout.text class="break-words font-mono text-xs">{{ $alert->replica_error }}</flux:callout.text>
                </flux:callout>
            @endif
        @else
            <flux:callout icon="clock" heading="No incident detail was recorded">
                <flux:callout.text>
                    This alert predates the monitor keeping the episode behind it. The message it carried is below.
                </flux:callout.text>
            </flux:callout>
        @endif

        <flux:separator variant="subtle" />

        <div class="space-y-3">
            <div>
                <flux:text size="sm" class="uppercase tracking-wide">Message sent</flux:text>
                <flux:text class="mt-1 break-words">{{ $alert->summary ?? '—' }}</flux:text>
            </div>

            <div>
                <flux:text size="sm" class="uppercase tracking-wide">Emailed to</flux:text>
                <flux:text class="mt-1 break-words">
                    {{ $alert->recipients === [] ? 'nobody' : implode(', ', $alert->recipients) }}
                </flux:text>
            </div>

            @if ($alert->delivery_error)
                <flux:callout color="red" icon="envelope" heading="Not delivered">
                    <flux:callout.text class="break-words">{{ $alert->delivery_error }}</flux:callout.text>
                </flux:callout>
            @endif
        </div>

        @if ($alert->serverPair)
            <div class="flex justify-end">
                <flux:button :href="route('pairs.show', $alert->serverPair)" size="sm" wire:navigate>
                    Open {{ $alert->serverPair->name }}
                </flux:button>
            </div>
        @endif
    </div>
</flux:modal>
