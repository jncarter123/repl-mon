<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\Endpoint;
use App\Models\ServerPair;
use App\Services\PairConnectionFactory;
use Illuminate\Database\Connection;
use Throwable;

/**
 * A connection factory where every connection fails with a chosen error, so the
 * provisioner's handling of refusals and faults can be exercised without two
 * MariaDB servers to hand.
 *
 * assertSafeIdentifier() is inherited untouched — the identifier lock is part of
 * what these tests are checking.
 */
class FailingConnections extends PairConnectionFactory
{
    public int $forgotten = 0;

    public function __construct(protected Throwable $error) {}

    public function connection(ServerPair $pair, Endpoint $endpoint): Connection
    {
        throw $this->error;
    }

    public function serverConnection(ServerPair $pair, Endpoint $endpoint): Connection
    {
        throw $this->error;
    }

    public function forget(ServerPair $pair): void
    {
        $this->forgotten++;
    }
}
