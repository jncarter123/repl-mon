<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Endpoint;
use App\Models\ServerPair;
use App\Support\DatabaseError;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use PDOException;
use Throwable;

/**
 * Answers "can this app actually use these credentials", used by the pair form
 * and by `replication:test`. Deliberately reports each capability separately —
 * connecting, the heartbeat schema being there, seeing the heartbeat table, and
 * being allowed to read replication status are four different things, and a
 * half-working pair should say which half.
 *
 * @phpstan-type TestResult array{ok: bool, message: string, version: ?string, schema_present: bool, heartbeat_table: bool, status_readable: ?bool, status_message: ?string}
 */
class ConnectionTester
{
    /** MariaDB's "Unknown database" — the schema is not there *yet*. */
    private const UNKNOWN_DATABASE = 1049;

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
            'schema_present' => false,
            'heartbeat_table' => false,
            'status_readable' => null,
            'status_message' => null,
        ];

        try {
            try {
                $connection = $this->connections->connection($pair, $endpoint);
                $result = $this->describe($connection, $pair, $endpoint, $result);
                $result['schema_present'] = true;

                try {
                    $result['heartbeat_table'] = $this->heartbeats->tableExists($connection, $pair);
                } catch (Throwable $e) {
                    $result['message'] .= ' Could not inspect the schema: '.DatabaseError::describe($e, $pair);
                }
            } catch (Throwable $e) {
                if (! $this->isUnknownDatabase($e)) {
                    throw $e;
                }

                // The credentials are not the problem — the schema simply is not
                // there yet, and the DSN names it, so the connect fails before
                // anything can say so. Ask the server itself instead: a test
                // that reads "could not connect" here is what sends somebody off
                // to create the database by hand, when setting the pair up would
                // have done it.
                $schema = (string) $pair->{"{$endpoint->value}_database"};

                $connection = $this->connections->serverConnection($pair, $endpoint);
                $result = $this->describe($connection, $pair, $endpoint, $result);
                $result['message'] .= " The database `{$schema}` is not there yet — setting up the heartbeat creates it.";
            }
        } catch (Throwable $e) {
            $result['message'] = DatabaseError::describe($e, $pair);
        } finally {
            $this->connections->forget($pair);
        }

        return $result;
    }

    /**
     * The part that is the same either way: the server answers, and on the
     * replica it is asked whether replication status is readable — a grant
     * that is worth knowing about before the schema exists, not after.
     *
     * @param  TestResult  $result
     * @return TestResult
     */
    protected function describe(Connection $connection, ServerPair $pair, Endpoint $endpoint, array $result): array
    {
        $version = $connection->selectOne('SELECT VERSION() AS version');

        $result['version'] = $version?->version;
        $result['ok'] = true;
        $result['message'] = "Connected to {$endpoint->label()} ({$result['version']}).";

        if ($endpoint === Endpoint::Replica) {
            [$result['status_readable'], $result['status_message']] = $this->probeStatusGrant($pair, $connection);
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

    protected function isUnknownDatabase(Throwable $e): bool
    {
        $previous = $e instanceof QueryException ? $e->getPrevious() : $e;

        if ($previous instanceof PDOException && is_array($previous->errorInfo)) {
            if (($previous->errorInfo[1] ?? null) === self::UNKNOWN_DATABASE) {
                return true;
            }
        }

        return str_contains(strtolower($e->getMessage()), 'unknown database');
    }
}
