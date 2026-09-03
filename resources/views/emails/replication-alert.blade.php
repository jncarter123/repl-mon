@use('App\Enums\AlertKind')
@use('App\Support\Duration')

<x-mail::message>
# {{ $kind === AlertKind::Recovery ? 'Replication recovered' : 'Replication problem' }}

@if ($kind === AlertKind::Recovery)
**{{ $pair->name }}** is replicating normally again{{ $incident ? ' after ' . $incident->duration() . ' ' . strtolower($incident->worstStatus->label()) : '' }}.
@else
**{{ $pair->name }}** is reporting **{{ $check->status->label() }}**.
@endif

{{ $check->message }}

@if ($incident)
<x-mail::panel>
**What happened**

{{ $incident->headline() }}, {{ $incident->startedBeforeWindow ? 'already going by' : 'starting' }} {{ $incident->startedAt->toDayDateTimeString() }} UTC.

_First failing check:_ {{ $incident->firstFailureMessage }}
@if (count($incident->statusCounts) > 1)

_Seen during the episode:_ {{ $incident->statusBreakdown() }}
@endif
@if ($incident->peakLagSeconds !== null)

_Worst lag measured:_ {{ Duration::humanize($incident->peakLagSeconds) }}
@endif
@if ($incident->replicaError)

_Replica reported:_ {{ $incident->replicaError }}
@endif
</x-mail::panel>
@endif

<x-mail::table>
| | |
|:--|:--|
| Primary | `{{ $pair->primaryLabel() }}` |
| Replica | `{{ $pair->replicaLabel() }}` |
| Measured lag | {{ Duration::humanize($check->lag_seconds) }} |
| Lag threshold | {{ $pair->lag_threshold_seconds }}s |
@if ($check->io_running !== null || $check->sql_running !== null)
| Replica threads | IO: {{ $check->io_running ?? 'unknown' }} &nbsp;·&nbsp; SQL: {{ $check->sql_running ?? 'unknown' }} |
@endif
@if ($check->seconds_behind_source !== null)
| Seconds_Behind_Source | {{ $check->seconds_behind_source }} |
@endif
| Checked at | {{ $check->checked_at?->toDayDateTimeString() }} UTC |
@if ($incident)
| Failing since | {{ $incident->startedBeforeWindow ? 'at or before ' : '' }}{{ $incident->startedAt->toDayDateTimeString() }} UTC ({{ $incident->duration() }}) |
| Failed checks | {{ $incident->failedChecks }} |
@endif
</x-mail::table>

@if ($check->replica_error && $check->replica_error !== $incident?->replicaError)
<x-mail::panel>
**Last replica error**

{{ $check->replica_error }}
</x-mail::panel>
@endif

@if ($check->status_query_error)
_Replication status could not be read from the replica: {{ $check->status_query_error }} — lag is still being measured from the heartbeat._
@endif

<x-mail::button :url="route('pairs.show', $pair)">
Open {{ $pair->name }}
</x-mail::button>

{{ config('app.name') }}
</x-mail::message>
