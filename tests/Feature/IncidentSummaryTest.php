<?php

declare(strict_types=1);

use App\Data\IncidentSummary;
use App\Enums\CheckStatus;
use App\Models\ReplicationCheck;
use App\Models\ServerPair;
use App\Services\IncidentSummariser;

// Timestamps land in SQLite without sub-second precision, and the point of
// every assertion below is a boundary in the history — so pin the clock.
beforeEach(function () {
    $this->freezeSecond();
});

/**
 * @param  list<array{0: CheckStatus, 1: int, 2?: float|null, 3?: string|null}>  $rows
 *                                                                                      status, minutes ago, lag, replica error
 */
function historyFor(ServerPair $pair, array $rows): void
{
    foreach ($rows as [$status, $minutesAgo, $lag, $error]) {
        ReplicationCheck::factory()->create([
            'server_pair_id' => $pair->getKey(),
            'status' => $status,
            'lag_seconds' => $lag,
            'replica_error' => $error,
            'message' => $status->label().' at '.$minutesAgo.'m ago',
            'checked_at' => now()->subMinutes($minutesAgo),
        ]);
    }
}

it('describes the run of failing checks behind an alert', function () {
    $pair = ServerPair::factory()->create();

    historyFor($pair, [
        [CheckStatus::Ok, 20, 0.3, null],
        [CheckStatus::Lagging, 19, 120.0, null],
        [CheckStatus::Broken, 18, 300.0, 'Error 1062: Duplicate entry'],
        [CheckStatus::Broken, 17, 900.0, null],
        [CheckStatus::Ok, 16, 0.4, null],
    ]);

    $latest = $pair->checks()->latest('checked_at')->first();

    $incident = app(IncidentSummariser::class)->summarise($pair, $latest);

    expect($incident)->not->toBeNull()
        ->and($incident->failedChecks)->toBe(3)
        ->and($incident->worstStatus)->toBe(CheckStatus::Broken)
        ->and($incident->startedAt->equalTo(now()->subMinutes(19)))->toBeTrue()
        ->and($incident->durationSeconds)->toBe(180)
        ->and($incident->peakLagSeconds)->toBe(900.0)
        ->and($incident->firstFailureMessage)->toContain('Lagging')
        ->and($incident->replicaError)->toBe('Error 1062: Duplicate entry')
        ->and($incident->statusCounts)->toBe(['broken' => 2, 'lagging' => 1])
        ->and($incident->statusBreakdown())->toBe('Broken ×2, Lagging ×1')
        // A healthy check sits immediately before the run, so the start is exact.
        ->and($incident->startedBeforeWindow)->toBeFalse()
        ->and($incident->headline())->toBe('Broken for 3m — 3 failed checks');
});

it('stops at the healthy check before the episode, so an older outage is not swept in', function () {
    $pair = ServerPair::factory()->create();

    historyFor($pair, [
        [CheckStatus::Broken, 60, 500.0, 'ancient history'],
        [CheckStatus::Ok, 59, 0.2, null],
        [CheckStatus::Broken, 3, 10.0, null],
        [CheckStatus::Broken, 2, 12.0, null],
    ]);

    $incident = app(IncidentSummariser::class)->summarise($pair, $pair->checks()->latest('checked_at')->first());

    expect($incident->failedChecks)->toBe(2)
        ->and($incident->replicaError)->toBeNull()
        ->and($incident->startedAt->equalTo(now()->subMinutes(3)))->toBeTrue();
});

it('summarises an outage that is still going, measured to the check being alerted on', function () {
    $pair = ServerPair::factory()->create();

    historyFor($pair, [
        [CheckStatus::Unreachable, 10, null, null],
        [CheckStatus::Unreachable, 9, null, null],
    ]);

    $incident = app(IncidentSummariser::class)->summarise($pair, $pair->checks()->latest('checked_at')->first());

    expect($incident->failedChecks)->toBe(2)
        ->and($incident->worstStatus)->toBe(CheckStatus::Unreachable)
        ->and($incident->peakLagSeconds)->toBeNull()
        ->and($incident->durationSeconds)->toBe(60)
        // Nothing healthy before it in the history, so the start is a bound.
        ->and($incident->startedBeforeWindow)->toBeTrue()
        ->and($incident->duration())->toBe('at least 1m');
});

it('has nothing to say when the pair has never failed', function () {
    $pair = ServerPair::factory()->create();

    historyFor($pair, [
        [CheckStatus::Ok, 2, 0.2, null],
        [CheckStatus::Ok, 1, 0.3, null],
    ]);

    expect(app(IncidentSummariser::class)->summarise($pair, $pair->checks()->latest('checked_at')->first()))
        ->toBeNull();
});

it('ignores other pairs entirely', function () {
    $pair = ServerPair::factory()->create();
    $other = ServerPair::factory()->create();

    historyFor($other, [[CheckStatus::Broken, 5, 99.0, 'not ours']]);
    historyFor($pair, [[CheckStatus::Broken, 4, 5.0, null]]);

    $incident = app(IncidentSummariser::class)->summarise($pair, $pair->checks()->latest('checked_at')->first());

    expect($incident->failedChecks)->toBe(1)
        ->and($incident->replicaError)->toBeNull();
});

it('reports a sub-minute episode in words rather than as zero', function () {
    $summary = IncidentSummary::fromChecks(
        [ReplicationCheck::factory()->make(['status' => CheckStatus::Broken, 'checked_at' => now(), 'message' => 'down'])],
        now(),
    );

    expect($summary->durationSeconds)->toBe(0)
        ->and($summary->duration())->toBe('an unknown time')
        ->and($summary->headline())->toBe('Broken for an unknown time — 1 failed check');
});
