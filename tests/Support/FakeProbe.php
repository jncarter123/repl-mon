<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Data\ProbeResult;
use App\Models\ServerPair;
use App\Services\ReplicationProbe;

/**
 * Stands in for the real probe so the checker's alerting rules can be exercised
 * without two MariaDB servers to hand.
 */
class FakeProbe extends ReplicationProbe
{
    public function __construct(public ProbeResult $result) {}

    public function probe(ServerPair $pair): ProbeResult
    {
        return $this->result;
    }
}
