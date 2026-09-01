<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\CheckOutcome;
use App\Data\ProbeResult;
use App\Enums\CheckStatus;
use App\Models\ServerPair;
use App\Support\Duration;

/**
 * Turns a ProbeResult into a verdict. Pure — no I/O, no clock, no model writes
 * — which is what makes every branch below cheap to pin down in a test.
 *
 * Order matters: a stopped SQL thread and a stale heartbeat are the same
 * outage seen twice, and the thread is the more useful thing to put in the
 * subject line.
 */
class ReplicationEvaluator
{
    public function evaluate(ServerPair $pair, ProbeResult $probe): CheckOutcome
    {
        if (! $probe->primaryReachable && ! $probe->replicaReachable) {
            return new CheckOutcome(
                CheckStatus::Unreachable,
                'Neither server answered. Primary: '.($probe->primaryError ?? 'no response')
                    .' Replica: '.($probe->replicaError ?? 'no response')
            );
        }

        if (! $probe->primaryReachable) {
            return new CheckOutcome(
                CheckStatus::Unreachable,
                "Could not reach the primary at {$pair->primaryLabel()}: ".($probe->primaryError ?? 'no response')
            );
        }

        if (! $probe->replicaReachable) {
            return new CheckOutcome(
                CheckStatus::Unreachable,
                "Could not reach the replica at {$pair->replicaLabel()}: ".($probe->replicaError ?? 'no response')
            );
        }

        if ($probe->notAReplica) {
            return new CheckOutcome(
                CheckStatus::Broken,
                "{$pair->replica_host} is not replicating: SHOW REPLICA STATUS returned no rows."
            );
        }

        if ($probe->threadsRunning() === false) {
            $message = sprintf(
                'Replication threads are not both running (IO: %s, SQL: %s).',
                $probe->ioRunning ?? 'unknown',
                $probe->sqlRunning ?? 'unknown',
            );

            if ($probe->replicaStatusError !== null) {
                $message .= ' Last error: '.$probe->replicaStatusError;
            }

            return new CheckOutcome(CheckStatus::Broken, $message);
        }

        if (! $probe->heartbeatRowFound) {
            return new CheckOutcome(
                CheckStatus::Broken,
                $probe->heartbeatError
                    ?? "No heartbeat row for this pair has reached {$pair->replica_database} on {$pair->replica_host}."
            );
        }

        $lag = $probe->lagSeconds ?? 0.0;
        $threshold = (float) $pair->lag_threshold_seconds;

        if ($lag > $threshold) {
            return new CheckOutcome(
                CheckStatus::Lagging,
                sprintf(
                    'Replica is %s behind the primary, past the %ss threshold.',
                    Duration::humanize($lag),
                    $pair->lag_threshold_seconds,
                )
            );
        }

        return new CheckOutcome(
            CheckStatus::Ok,
            sprintf('Replica is %s behind, within the %ss threshold.', Duration::humanize($lag), $pair->lag_threshold_seconds)
        );
    }
}
