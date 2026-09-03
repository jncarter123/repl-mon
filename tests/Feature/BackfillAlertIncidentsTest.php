<?php

declare(strict_types=1);

use App\Enums\AlertKind;
use App\Enums\CheckStatus;
use App\Models\ReplicationAlert;
use App\Models\ReplicationCheck;
use App\Models\ServerPair;

beforeEach(function () {
    $this->freezeSecond();
});

/**
 * A pair that broke overnight and fixed itself: healthy, then a run of
 * failures, then healthy again — with the recovery alert that was sent at the
 * time, carrying nothing but "recovered".
 */
function overnightHistory(ServerPair $pair): ReplicationAlert
{
    $rows = [
        [CheckStatus::Ok, 500, 0.3, null],
        [CheckStatus::Broken, 499, 60.0, 'Error 1236: Could not find GTID in binlog'],
        [CheckStatus::Broken, 498, 120.0, null],
        [CheckStatus::Lagging, 497, 240.0, null],
        [CheckStatus::Ok, 496, 0.4, null],
    ];

    $last = null;

    foreach ($rows as [$status, $minutesAgo, $lag, $error]) {
        $last = ReplicationCheck::factory()->create([
            'server_pair_id' => $pair->getKey(),
            'status' => $status,
            'lag_seconds' => $lag,
            'replica_error' => $error,
            'message' => $status->label().' — reconstructed from '.$minutesAgo.'m ago',
            'checked_at' => now()->subMinutes($minutesAgo),
        ]);
    }

    return ReplicationAlert::factory()->create([
        'server_pair_id' => $pair->getKey(),
        'replication_check_id' => $last->getKey(),
        'kind' => AlertKind::Recovery,
        'status' => CheckStatus::Ok,
        'subject' => "[{$pair->name}] Replication recovered",
        'summary' => 'Replica is 0.4s behind, within the 60s threshold.',
        'sent_at' => now()->subMinutes(496),
    ]);
}

it('reconstructs the outage behind an alert that was sent without one', function () {
    $pair = ServerPair::factory()->create(['name' => 'reporting']);
    $alert = overnightHistory($pair);

    $this->artisan('replication:backfill-alerts')
        ->expectsOutputToContain('Broken for 3m — 3 failed checks')
        ->assertSuccessful();

    $alert->refresh();

    expect($alert->hasIncident())->toBeTrue()
        ->and($alert->failed_checks)->toBe(3)
        ->and($alert->worst_status)->toBe(CheckStatus::Broken)
        ->and($alert->incident_duration_seconds)->toBe(180)
        ->and($alert->incident_truncated)->toBeFalse()
        ->and($alert->peak_lag_seconds)->toBe(240.0)
        ->and($alert->replica_error)->toBe('Error 1236: Could not find GTID in binlog')
        ->and($alert->status_counts)->toBe(['broken' => 2, 'lagging' => 1]);
});

it('leaves the record of what was actually sent alone', function () {
    $pair = ServerPair::factory()->create();
    $alert = overnightHistory($pair);

    $before = $alert->only(['subject', 'summary', 'recipients', 'sent_at', 'status', 'kind']);

    $this->artisan('replication:backfill-alerts')->assertSuccessful();

    expect($alert->fresh()->only(['subject', 'summary', 'recipients', 'sent_at', 'status', 'kind']))
        ->toEqual($before);
});

it('changes nothing on a dry run', function () {
    $pair = ServerPair::factory()->create();
    $alert = overnightHistory($pair);

    $this->artisan('replication:backfill-alerts', ['--dry-run' => true])
        ->expectsOutputToContain('Broken for 3m — 3 failed checks')
        ->expectsOutputToContain('nothing was written')
        ->assertSuccessful();

    expect($alert->fresh()->hasIncident())->toBeFalse();
});

it('says which alerts it could not reconstruct, and why', function () {
    $pair = ServerPair::factory()->create(['name' => 'pruned-away']);

    // The alert outlived its checks: alerts are kept for a year, checks for a
    // fortnight.
    ReplicationAlert::factory()->create([
        'server_pair_id' => $pair->getKey(),
        'kind' => AlertKind::Recovery,
        'sent_at' => now()->subDays(90),
    ]);

    $this->artisan('replication:backfill-alerts')
        ->expectsOutputToContain('has been pruned past here')
        ->assertSuccessful();

    expect(ReplicationAlert::query()->sole()->hasIncident())->toBeFalse();
});

it('marks a start it cannot see as a lower bound rather than inventing one', function () {
    $pair = ServerPair::factory()->create();

    // The surviving history opens mid-outage: no healthy check before it.
    foreach ([30, 29, 28] as $minutesAgo) {
        ReplicationCheck::factory()->create([
            'server_pair_id' => $pair->getKey(),
            'status' => CheckStatus::Broken,
            'message' => 'Replication threads are not both running (IO: No, SQL: Yes).',
            'checked_at' => now()->subMinutes($minutesAgo),
        ]);
    }

    ReplicationAlert::factory()->create([
        'server_pair_id' => $pair->getKey(),
        'sent_at' => now()->subMinutes(28),
    ]);

    $this->artisan('replication:backfill-alerts')
        ->expectsOutputToContain('lower bound')
        ->assertSuccessful();

    $alert = ReplicationAlert::query()->sole();

    expect($alert->incident_truncated)->toBeTrue()
        ->and($alert->incidentDuration())->toBe('at least 2m')
        ->and($alert->incidentHeadline())->toBe('Broken for at least 2m — 3 failed checks');
});

it('skips alerts that already carry the detail unless told otherwise', function () {
    $pair = ServerPair::factory()->create();
    $alert = overnightHistory($pair);

    $alert->forceFill([
        'incident_started_at' => now()->subYear(),
        'failed_checks' => 99,
        'worst_status' => CheckStatus::Unreachable,
    ])->save();

    $this->artisan('replication:backfill-alerts')
        ->expectsOutputToContain('No alerts to backfill')
        ->assertSuccessful();

    expect($alert->fresh()->failed_checks)->toBe(99);

    $this->artisan('replication:backfill-alerts', ['--all' => true])->assertSuccessful();

    expect($alert->fresh()->failed_checks)->toBe(3)
        ->and($alert->fresh()->worst_status)->toBe(CheckStatus::Broken);
});

it('can be pointed at one pair', function () {
    $wanted = ServerPair::factory()->create(['name' => 'payments']);
    $other = ServerPair::factory()->create(['name' => 'reporting']);

    $wantedAlert = overnightHistory($wanted);
    $otherAlert = overnightHistory($other);

    $this->artisan('replication:backfill-alerts', ['pair' => 'payments'])->assertSuccessful();

    expect($wantedAlert->fresh()->hasIncident())->toBeTrue()
        ->and($otherAlert->fresh()->hasIncident())->toBeFalse();
});
