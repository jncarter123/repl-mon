<?php

declare(strict_types=1);

namespace App\Data;

use Carbon\CarbonImmutable;

/**
 * Everything one pass of the probe learned about a pair. Facts only — the
 * verdict belongs to ReplicationEvaluator, so that it can be exercised without
 * a database on either end.
 */
final readonly class ProbeResult
{
    /**
     * @param  ?float  $lagSeconds  Measured entirely against this host's clock: we
     *                              stamp the beat on the primary and the replica
     *                              hands the same stamp back, so neither database
     *                              server's clock enters the arithmetic.
     * @param  ?string  $heartbeatError  A reachable server whose heartbeat table is
     *                                   missing or unreadable — a different problem
     *                                   from a server we could not reach at all.
     */
    public function __construct(
        public bool $primaryReachable = false,
        public ?string $primaryError = null,
        public bool $replicaReachable = false,
        public ?string $replicaError = null,
        public ?string $heartbeatError = null,
        public bool $heartbeatRowFound = false,
        public ?CarbonImmutable $beatWrittenAt = null,
        public ?CarbonImmutable $beatSeenAt = null,
        public ?float $lagSeconds = null,
        public ?string $ioRunning = null,
        public ?string $sqlRunning = null,
        public ?int $secondsBehindSource = null,
        public ?string $replicaStatusError = null,
        public ?string $statusQueryError = null,
        public bool $notAReplica = false,
        public int $durationMs = 0,
    ) {}

    /**
     * A thread column reads "Yes" when healthy; "No" and "Connecting" are both
     * reasons to look. Null means we never got to ask.
     */
    public function threadsRunning(): ?bool
    {
        if ($this->ioRunning === null && $this->sqlRunning === null) {
            return null;
        }

        return strcasecmp((string) $this->ioRunning, 'Yes') === 0
            && strcasecmp((string) $this->sqlRunning, 'Yes') === 0;
    }
}
