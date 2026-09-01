<?php

declare(strict_types=1);

namespace App\Services;

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
}
