<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Endpoint;
use App\Models\ServerPair;
use App\Support\DatabaseError;
use Illuminate\Database\Connection;
use Throwable;

/**
 * Answers "can this app actually use these credentials", used by the pair form
 * and by `replication:test`. Deliberately reports each capability separately —
 * connecting, seeing the heartbeat table, and being allowed to read replication
 * status are three different grants, and a half-working pair should say which
 * half.
 *
 * @phpstan-type TestResult array{ok: bool, message: string, version: ?string, heartbeat_table: bool, status_readable: ?bool, status_message: ?string}
 */
class ConnectionTester
{
    public function __construct(
        protected PairConnectionFactory $connections,
        protected HeartbeatManager $heartbeats,
    ) {}

    /**
     * @return TestResult
     */
    public function test(ServerPair $pair, Endpoint $endpoint): array
    {
        $result = [
            'ok' => false,
            'message' => '',
            'version' => null,
            'heartbeat_table' => false,
            'status_readable' => null,
            'status_message' => null,
        ];

        try {
            $connection = $this->connections->connection($pair, $endpoint);

            $version = $connection->selectOne('SELECT VERSION() AS version');
            $result['version'] = $version?->version;
            $result['ok'] = true;
            $result['message'] = "Connected to {$endpoint->label()} ({$result['version']}).";

            try {
                $result['heartbeat_table'] = $this->heartbeats->tableExists($connection, $pair);
            } catch (Throwable $e) {
                $result['message'] .= ' Could not inspect the schema: '.DatabaseError::describe($e, $pair);
            }

            if ($endpoint === Endpoint::Replica) {
                [$result['status_readable'], $result['status_message']] = $this->probeStatusGrant($pair, $connection);
            }
        } catch (Throwable $e) {
            $result['message'] = DatabaseError::describe($e, $pair);
        } finally {
            $this->connections->forget($pair);
        }

        return $result;
    }

    /**
     * @return array{0: bool, 1: string}
     */
    protected function probeStatusGrant(ServerPair $pair, Connection $connection): array
    {
        $error = null;

        foreach (['SHOW REPLICA STATUS', 'SHOW SLAVE STATUS'] as $statement) {
            try {
                $rows = $connection->select($statement);

                return $rows === []
                    ? [false, 'Readable, but the server reports no replication configured.']
                    : [true, 'Replication status is readable.'];
            } catch (Throwable $e) {
                $error = DatabaseError::describe($e, $pair);
            }
        }

        return [false, 'Replication status is not readable (REPLICATION CLIENT grant?): '.$error];
    }
}
