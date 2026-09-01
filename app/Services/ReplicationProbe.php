<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\ProbeResult;
use App\Enums\Endpoint;
use App\Models\ServerPair;
use App\Support\DatabaseError;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * One pass over a pair: stamp a beat on the primary, read it back off the
 * replica, and ask the replica what its own threads think. Gathers facts and
 * never decides anything — see ReplicationEvaluator.
 */
class ReplicationProbe
{
    public function __construct(
        protected PairConnectionFactory $connections,
        protected HeartbeatManager $heartbeats,
        protected ReplicaStatusReader $status,
    ) {}

    public function probe(ServerPair $pair): ProbeResult
    {
        $startedAt = hrtime(true);

        $primaryReachable = false;
        $primaryError = null;
        $heartbeatError = null;
        $written = null;
        $beatWrittenAt = null;

        try {
            $primary = $this->connections->connection($pair, Endpoint::Primary);
            $primary->select('SELECT 1');
            $primaryReachable = true;

            try {
                $beat = $this->heartbeats->writeBeat($primary, $pair);
                $written = $beat['number'];
                $beatWrittenAt = $beat['at'];
            } catch (Throwable $e) {
                $heartbeatError = 'Primary: '.DatabaseError::describe($e, $pair);
            }
        } catch (Throwable $e) {
            $primaryError = DatabaseError::describe($e, $pair);
        }

        $replicaReachable = false;
        $replicaError = null;
        $beatSeenAt = null;
        $lagSeconds = null;
        $heartbeatRowFound = false;
        $status = [];

        try {
            $replica = $this->connections->connection($pair, Endpoint::Replica);
            $replica->select('SELECT 1');
            $replicaReachable = true;

            try {
                $seen = $this->heartbeats->awaitBeat($replica, $pair, $written);

                if ($seen !== null) {
                    $heartbeatRowFound = true;
                    $beatSeenAt = $seen['at'];
                    $lagSeconds = max(0.0, round(
                        (CarbonImmutable::now('UTC')->getPreciseTimestamp(3) - $seen['at']->getPreciseTimestamp(3)) / 1000,
                        3
                    ));
                }
            } catch (Throwable $e) {
                $heartbeatError ??= 'Replica: '.DatabaseError::describe($e, $pair);
            }

            if ($pair->check_replica_status) {
                $status = $this->status->read($replica, $pair);
            }
        } catch (Throwable $e) {
            $replicaError = DatabaseError::describe($e, $pair);
        } finally {
            $this->connections->forget($pair);
        }

        return new ProbeResult(
            primaryReachable: $primaryReachable,
            primaryError: $primaryError,
            replicaReachable: $replicaReachable,
            replicaError: $replicaError,
            heartbeatError: $heartbeatError,
            heartbeatRowFound: $heartbeatRowFound,
            beatWrittenAt: $beatWrittenAt,
            beatSeenAt: $beatSeenAt,
            lagSeconds: $lagSeconds,
            ioRunning: $status['io'] ?? null,
            sqlRunning: $status['sql'] ?? null,
            secondsBehindSource: $status['behind'] ?? null,
            replicaStatusError: $status['error'] ?? null,
            statusQueryError: $status['query_error'] ?? null,
            notAReplica: $status['not_a_replica'] ?? false,
            durationMs: (int) round((hrtime(true) - $startedAt) / 1_000_000),
        );
    }
}
