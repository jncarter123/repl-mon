<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\ProbeResult;
use App\Enums\Endpoint;
use App\Models\ServerPair;
use App\Support\DatabaseError;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
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
                $seen = $this->awaitBeat($replica, $pair, $written);

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
                $status = $this->replicaStatus($replica, $pair);
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

    /**
     * Give replication a moment to carry the beat we just wrote before
     * measuring. Without this window a perfectly healthy pair reads as a full
     * interval behind on every check, because the newest row on the replica is
     * still the previous minute's beat.
     *
     * The wait ends the instant our own beat shows up, so a healthy pair costs
     * one poll, and a genuinely lagging pair falls through to the last beat
     * that did arrive — which is the number we actually want.
     *
     * @return array{number: int, at: CarbonImmutable}|null
     */
    protected function awaitBeat(Connection $replica, ServerPair $pair, ?int $written): ?array
    {
        $seen = $this->heartbeats->readBeat($replica, $pair);

        if ($written === null || ($seen !== null && $seen['number'] >= $written)) {
            return $seen;
        }

        $budgetMs = max(0, (int) config('replication.settle_timeout_ms'));
        $pollMs = max(50, (int) config('replication.settle_poll_ms'));
        $deadline = hrtime(true) + ($budgetMs * 1_000_000);

        while (hrtime(true) < $deadline) {
            usleep($pollMs * 1000);

            $seen = $this->heartbeats->readBeat($replica, $pair);

            if ($seen !== null && $seen['number'] >= $written) {
                return $seen;
            }
        }

        return $seen;
    }

    /**
     * SHOW REPLICA STATUS on MariaDB 10.5+/MySQL 8.0.22+, SHOW SLAVE STATUS on
     * anything older, and the column names moved with it.
     *
     * Being refused the grant is recorded but is not itself a fault — plenty of
     * shops will not hand out REPLICATION CLIENT, and the heartbeat still
     * answers the question this app exists to answer.
     *
     * @return array<string, mixed>
     */
    protected function replicaStatus(Connection $replica, ServerPair $pair): array
    {
        $rows = null;
        $lastError = null;

        foreach (['SHOW REPLICA STATUS', 'SHOW SLAVE STATUS'] as $statement) {
            try {
                $rows = $replica->select($statement);
                $lastError = null;
                break;
            } catch (Throwable $e) {
                $lastError = DatabaseError::describe($e, $pair);
            }
        }

        if ($lastError !== null) {
            return ['query_error' => $lastError];
        }

        if ($rows === null || $rows === []) {
            // It answered, and the answer was "I am not replicating anything".
            return ['not_a_replica' => true];
        }

        $row = (array) $rows[0];

        $error = $this->firstNonEmpty($row, ['Last_Error', 'Last_SQL_Error', 'Last_IO_Error']);
        $behind = $this->column($row, ['Seconds_Behind_Source', 'Seconds_Behind_Master']);

        return [
            'io' => $this->column($row, ['Replica_IO_Running', 'Slave_IO_Running']),
            'sql' => $this->column($row, ['Replica_SQL_Running', 'Slave_SQL_Running']),
            'behind' => is_numeric($behind) ? (int) $behind : null,
            'error' => $error === null ? null : Str::limit($error, DatabaseError::MAX_LENGTH),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $candidates
     */
    protected function column(array $row, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            foreach ($row as $key => $value) {
                if (strcasecmp((string) $key, $candidate) === 0 && $value !== null) {
                    return (string) $value;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $candidates
     */
    protected function firstNonEmpty(array $row, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $value = $this->column($row, [$candidate]);

            if ($value !== null && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }
}
