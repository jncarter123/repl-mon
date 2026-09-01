<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\Endpoint;
use App\Models\ServerPair;
use App\Services\PairConnectionFactory;
use Illuminate\Database\Connection;
use Throwable;

/**
 * A factory standing in for a server whose heartbeat schema has not been
 * created yet: the schema-scoped connection fails the way MariaDB fails it —
 * before any statement runs — while the server itself answers perfectly well.
 */
class SchemalessConnections extends PairConnectionFactory
{
    public int $serverConnections = 0;

    public function __construct(
        protected Throwable $error,
        protected Connection $server = new StubServerConnection,
    ) {}

    public function connection(ServerPair $pair, Endpoint $endpoint): Connection
    {
        throw $this->error;
    }

    public function serverConnection(ServerPair $pair, Endpoint $endpoint): Connection
    {
        $this->serverConnections++;

        return $this->server;
    }

    public function forget(ServerPair $pair): void {}
}
