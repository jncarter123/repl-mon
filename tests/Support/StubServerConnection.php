<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\Connection;
use RuntimeException;
use Throwable;

/**
 * A Connection that answers the two questions the connection test asks a server
 * — its version, and its replication status — without one to answer them. The
 * suite has no MariaDB; what is under test is what the tester makes of the
 * answers.
 */
class StubServerConnection extends Connection
{
    /**
     * @param  list<object>|Throwable  $status  What SHOW REPLICA STATUS returns, or throws.
     */
    public function __construct(
        protected string $version = '11.4.2-MariaDB',
        protected array|Throwable $status = [],
    ) {
        parent::__construct(fn () => throw new RuntimeException('The stub never opens a PDO.'), 'information_schema');
    }

    /** @param  array<mixed>  $bindings */
    public function selectOne($query, $bindings = [], $useReadPdo = true): ?object
    {
        return (object) ['version' => $this->version];
    }

    /**
     * @param  array<mixed>  $bindings
     * @param  array<mixed>  $fetchUsing
     * @return list<object>
     */
    public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = []): array
    {
        if ($this->status instanceof Throwable) {
            throw $this->status;
        }

        return $this->status;
    }
}
