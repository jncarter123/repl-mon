<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Endpoint;
use App\Models\ServerPair;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;

/**
 * The heartbeat is one row per pair, upserted on the primary and read back off
 * the replica. One row rather than an ever-growing log: the question is "how
 * old is the newest thing that got across", and a table that answers it without
 * needing to be pruned is one less thing to go wrong on a customer's server.
 */
class HeartbeatManager
{
    public function __construct(protected PairConnectionFactory $connections) {}

    public function tableFor(ServerPair $pair): string
    {
        return $this->connections->assertSafeIdentifier(
            $pair->heartbeat_table ?: (string) config('replication.heartbeat_table')
        );
    }

    /**
     * Create the heartbeat table on whichever side is handed in. Run against
     * the primary and let replication carry the DDL, or run it on both when
     * the schema is not replicated.
     */
    public function install(Connection $connection, ServerPair $pair): void
    {
        $table = $this->tableFor($pair);

        $connection->statement(<<<SQL
            CREATE TABLE IF NOT EXISTS `{$table}` (
                `monitor_key` VARCHAR(64) NOT NULL,
                `pair_name` VARCHAR(191) NULL,
                `beat_at` DATETIME(3) NOT NULL,
                `beat_number` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`monitor_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL);
    }

    /**
     * The schema a given side keeps its heartbeat in. Interpolated into DDL, so
     * it goes through the same identifier check as the table name.
     */
    public function schemaFor(ServerPair $pair, Endpoint $endpoint): string
    {
        return $this->connections->assertSafeIdentifier(
            (string) $pair->{"{$endpoint->value}_database"}
        );
    }

    /**
     * Create the heartbeat schema. IF NOT EXISTS, because setting a pair up is
     * something people do twice, and because this must never be capable of
     * disturbing a schema that is already there and full of somebody's data.
     *
     * Needs a connection that has not already selected this schema — see
     * PairConnectionFactory::serverConnection().
     */
    public function createSchema(Connection $connection, ServerPair $pair, Endpoint $endpoint): void
    {
        $schema = $this->schemaFor($pair, $endpoint);

        $connection->statement("CREATE DATABASE IF NOT EXISTS `{$schema}` DEFAULT CHARACTER SET utf8mb4");
    }

    public function schemaExists(Connection $connection, ServerPair $pair, Endpoint $endpoint): bool
    {
        return $connection->selectOne(
            'SELECT 1 AS present FROM information_schema.schemata WHERE schema_name = ? LIMIT 1',
            [$this->schemaFor($pair, $endpoint)]
        ) !== null;
    }

    /**
     * Like tableExists(), but naming the schema rather than relying on DATABASE()
     * — the provisioning connection has information_schema selected, so the
     * current database is not the one we are asking about.
     */
    public function tableExistsIn(Connection $connection, ServerPair $pair, Endpoint $endpoint): bool
    {
        return $connection->selectOne(
            'SELECT 1 AS present FROM information_schema.tables WHERE table_schema = ? AND table_name = ? LIMIT 1',
            [$this->schemaFor($pair, $endpoint), $this->tableFor($pair)]
        ) !== null;
    }

    public function tableExists(Connection $connection, ServerPair $pair): bool
    {
        $table = $this->tableFor($pair);

        return $connection->selectOne(
            'SELECT 1 AS present FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1',
            [$table]
        ) !== null;
    }

    /**
     * Stamp a fresh beat on the primary and report the row as it now stands.
     *
     * @return array{number: int, at: CarbonImmutable}
     */
    public function writeBeat(Connection $connection, ServerPair $pair): array
    {
        $table = $this->tableFor($pair);
        $at = CarbonImmutable::now('UTC');

        $connection->statement(<<<SQL
            INSERT INTO `{$table}` (`monitor_key`, `pair_name`, `beat_at`, `beat_number`)
            VALUES (?, ?, ?, 1)
            ON DUPLICATE KEY UPDATE
                `beat_at` = VALUES(`beat_at`),
                `pair_name` = VALUES(`pair_name`),
                `beat_number` = `beat_number` + 1
            SQL, [$pair->monitor_key, $pair->name, $at->format('Y-m-d H:i:s.v')]);

        $row = $this->readBeat($connection, $pair);

        return [
            'number' => $row['number'] ?? 1,
            'at' => $row['at'] ?? $at,
        ];
    }

    /**
     * @return array{number: int, at: CarbonImmutable}|null
     */
    public function readBeat(Connection $connection, ServerPair $pair): ?array
    {
        $table = $this->tableFor($pair);

        $row = $connection->selectOne(
            "SELECT `beat_number`, `beat_at` FROM `{$table}` WHERE `monitor_key` = ? LIMIT 1",
            [$pair->monitor_key]
        );

        if ($row === null) {
            return null;
        }

        return [
            'number' => (int) $row->beat_number,
            // Written as a UTC string by this host, so it is read back as one.
            'at' => CarbonImmutable::parse($row->beat_at, 'UTC'),
        ];
    }

    /**
     * Wait for a particular beat to show up on the replica.
     *
     * This is not a busy-wait to be optimised away. After writing beat N, the
     * newest row on the replica is still beat N-1 until replication carries it
     * across; measuring immediately would report a full check interval of lag on
     * every healthy pair. The loop exits the instant our own beat lands, so the
     * healthy path costs one query, and a genuinely lagging pair falls through to
     * the last beat that did arrive — which is the number we actually want.
     *
     * @param  ?int  $budgetMs  How long to wait. Defaults to the steady-state
     *                          settle window; provisioning passes a longer one,
     *                          because a one-off setup check can afford to be
     *                          patient and a per-minute check cannot.
     * @return array{number: int, at: CarbonImmutable}|null
     */
    public function awaitBeat(Connection $replica, ServerPair $pair, ?int $written, ?int $budgetMs = null): ?array
    {
        $seen = $this->readBeat($replica, $pair);

        if ($written === null || ($seen !== null && $seen['number'] >= $written)) {
            return $seen;
        }

        $budgetMs = max(0, $budgetMs ?? (int) config('replication.settle_timeout_ms'));
        $pollMs = max(50, (int) config('replication.settle_poll_ms'));
        $deadline = hrtime(true) + ($budgetMs * 1_000_000);

        while (hrtime(true) < $deadline) {
            usleep($pollMs * 1000);

            $seen = $this->readBeat($replica, $pair);

            if ($seen !== null && $seen['number'] >= $written) {
                return $seen;
            }
        }

        return $seen;
    }
}
