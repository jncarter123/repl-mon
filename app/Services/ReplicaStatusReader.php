<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ServerPair;
use App\Support\DatabaseError;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Throwable;

/**
 * Asks a replica what its own threads think, in both of the vocabularies
 * MariaDB has used for the question.
 *
 * SHOW REPLICA STATUS on MariaDB 10.5+/MySQL 8.0.22+, SHOW SLAVE STATUS on
 * anything older, and the column names moved with it. Both spellings are tried
 * and every column is read case-insensitively from both — do not collapse this
 * to one spelling.
 *
 * Being refused the grant is recorded but is not itself a fault: plenty of shops
 * will not hand out REPLICATION CLIENT, and the heartbeat answers the question
 * this app exists to answer on its own.
 *
 * Lives here rather than inside ReplicationProbe so that the setup-time
 * diagnosis can ask the same question without a second implementation of the
 * rules above.
 */
class ReplicaStatusReader
{
    /**
     * @return array<string, mixed>
     */
    public function read(Connection $replica, ?ServerPair $pair = null): array
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
     * A thread that is not saying "Yes" — the phrase for it, or null when both
     * threads are running or we could not tell.
     *
     * @param  array<string, mixed>  $status
     */
    public function stoppedThread(array $status): ?string
    {
        foreach (['io' => 'IO', 'sql' => 'SQL'] as $key => $label) {
            $value = $status[$key] ?? null;

            if (is_string($value) && strcasecmp($value, 'Yes') !== 0) {
                return "The replica's {$label} thread is not running (it reports \"{$value}\").";
            }
        }

        return null;
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
