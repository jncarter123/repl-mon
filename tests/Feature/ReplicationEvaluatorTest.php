<?php

declare(strict_types=1);

use App\Data\ProbeResult;
use App\Enums\CheckStatus;
use App\Models\ServerPair;
use App\Services\ReplicationEvaluator;

function evaluate(ProbeResult $probe, int $threshold = 60): array
{
    $pair = ServerPair::factory()->make(['lag_threshold_seconds' => $threshold]);
    $outcome = app(ReplicationEvaluator::class)->evaluate($pair, $probe);

    return [$outcome->status, $outcome->message];
}

it('is healthy when the beat arrives inside the threshold', function () {
    [$status] = evaluate(new ProbeResult(
        primaryReachable: true,
        replicaReachable: true,
        heartbeatRowFound: true,
        lagSeconds: 0.4,
        ioRunning: 'Yes',
        sqlRunning: 'Yes',
    ));

    expect($status)->toBe(CheckStatus::Ok);
});

it('is lagging once the beat is older than the threshold', function () {
    [$status, $message] = evaluate(new ProbeResult(
        primaryReachable: true,
        replicaReachable: true,
        heartbeatRowFound: true,
        lagSeconds: 90.0,
        ioRunning: 'Yes',
        sqlRunning: 'Yes',
    ), threshold: 60);

    expect($status)->toBe(CheckStatus::Lagging)
        ->and($message)->toContain('1m 30s');
});

it('treats a lag exactly on the threshold as healthy', function () {
    [$status] = evaluate(new ProbeResult(
        primaryReachable: true,
        replicaReachable: true,
        heartbeatRowFound: true,
        lagSeconds: 60.0,
        ioRunning: 'Yes',
        sqlRunning: 'Yes',
    ), threshold: 60);

    expect($status)->toBe(CheckStatus::Ok);
});

it('reports a stopped thread as broken even while the heartbeat still looks fresh', function () {
    // The SQL thread has just stopped, so the last beat that got across is
    // still well inside the threshold. Reading the thread state is the whole
    // reason this catches the outage now rather than a minute from now.
    [$status, $message] = evaluate(new ProbeResult(
        primaryReachable: true,
        replicaReachable: true,
        heartbeatRowFound: true,
        lagSeconds: 1.0,
        ioRunning: 'Yes',
        sqlRunning: 'No',
        replicaStatusError: 'Duplicate entry for key PRIMARY',
    ));

    expect($status)->toBe(CheckStatus::Broken)
        ->and($message)->toContain('SQL: No')
        ->and($message)->toContain('Duplicate entry');
});

it('treats "Connecting" as a stopped IO thread', function () {
    [$status] = evaluate(new ProbeResult(
        primaryReachable: true,
        replicaReachable: true,
        heartbeatRowFound: true,
        lagSeconds: 1.0,
        ioRunning: 'Connecting',
        sqlRunning: 'Yes',
    ));

    expect($status)->toBe(CheckStatus::Broken);
});

it('is broken when the replica is not replicating at all', function () {
    [$status, $message] = evaluate(new ProbeResult(
        primaryReachable: true,
        replicaReachable: true,
        heartbeatRowFound: true,
        lagSeconds: 0.2,
        notAReplica: true,
    ));

    expect($status)->toBe(CheckStatus::Broken)
        ->and($message)->toContain('not replicating');
});

it('is broken when no beat has ever reached the replica', function () {
    [$status, $message] = evaluate(new ProbeResult(
        primaryReachable: true,
        replicaReachable: true,
        heartbeatError: 'Replica: table does not exist',
    ));

    expect($status)->toBe(CheckStatus::Broken)
        ->and($message)->toContain('table does not exist');
});

it('is unreachable when a server does not answer', function () {
    [$primaryDown] = evaluate(new ProbeResult(replicaReachable: true, primaryError: 'Connection refused'));
    [$replicaDown] = evaluate(new ProbeResult(primaryReachable: true, replicaError: 'Connection refused'));

    expect($primaryDown)->toBe(CheckStatus::Unreachable)
        ->and($replicaDown)->toBe(CheckStatus::Unreachable);
});

it('does not fault a pair merely because replication status could not be read', function () {
    // No REPLICATION CLIENT grant. The heartbeat still answers the question.
    [$status] = evaluate(new ProbeResult(
        primaryReachable: true,
        replicaReachable: true,
        heartbeatRowFound: true,
        lagSeconds: 2.0,
        statusQueryError: 'Access denied; you need the REPLICATION CLIENT privilege',
    ));

    expect($status)->toBe(CheckStatus::Ok);
});
