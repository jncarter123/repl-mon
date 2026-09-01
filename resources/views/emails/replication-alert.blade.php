@use('App\Enums\AlertKind')
@use('App\Support\Duration')

<x-mail::message>
# {{ $kind === AlertKind::Recovery ? 'Replication recovered' : 'Replication problem' }}

@if ($kind === AlertKind::Recovery)
**{{ $pair->name }}** is replicating normally again.
@else
**{{ $pair->name }}** is reporting **{{ $check->status->label() }}**.
@endif

{{ $check->message }}

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
</x-mail::table>

@if ($check->replica_error)
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
