<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Endpoint;
use App\Models\ServerPair;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * Builds throwaway Laravel connections to a pair's two servers from the
 * credentials stored (encrypted) on the pair. Nothing here is registered in
 * config/database.php: a pair can be added, edited or deleted at runtime and
 * the connection has to follow it.
 */
class PairConnectionFactory
{
    public function connection(ServerPair $pair, Endpoint $endpoint): Connection
    {
        $name = $this->connectionName($pair, $endpoint);

        Config::set("database.connections.{$name}", $this->configFor($pair, $endpoint));

        // Purge first: a pair edited in the UI must not keep talking to the
        // host it named a minute ago.
        DB::purge($name);

        $connection = DB::connection($name);

        $this->applyStatementTimeout($connection);

        return $connection;
    }

    public function forget(ServerPair $pair): void
    {
        foreach (Endpoint::cases() as $endpoint) {
            $name = $this->connectionName($pair, $endpoint);

            DB::purge($name);
            Config::set("database.connections.{$name}", null);
        }
    }

    public function connectionName(ServerPair $pair, Endpoint $endpoint): string
    {
        return 'pair_'.($pair->getKey() ?? 'draft').'_'.$endpoint->value;
    }

    /**
     * A table name is interpolated straight into the heartbeat SQL — MariaDB
     * takes no placeholder for an identifier — so it is validated here rather
     * than trusted from the form.
     */
    public function assertSafeIdentifier(string $identifier): string
    {
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $identifier) !== 1) {
            throw new InvalidArgumentException("Unsafe SQL identifier: {$identifier}");
        }

        return $identifier;
    }

    /**
     * @return array<string, mixed>
     */
    protected function configFor(ServerPair $pair, Endpoint $endpoint): array
    {
        $prefix = $endpoint->value;

        return [
            'driver' => 'mariadb',
            'host' => $pair->{"{$prefix}_host"},
            'port' => $pair->{"{$prefix}_port"},
            'database' => $pair->{"{$prefix}_database"},
            'username' => $pair->{"{$prefix}_username"},
            'password' => $pair->{"{$prefix}_password"} ?? '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => $this->pdoOptions($pair, (bool) $pair->{"{$prefix}_use_tls"}),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    protected function pdoOptions(ServerPair $pair, bool $useTls): array
    {
        $options = [
            PDO::ATTR_TIMEOUT => $pair->connect_timeout_seconds ?: (int) config('replication.connect_timeout'),
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ];

        if (! $useTls) {
            return $options;
        }

        $ca = config('replication.ssl_ca');

        if (is_string($ca) && $ca !== '' && defined('PDO::MYSQL_ATTR_SSL_CA')) {
            $options[PDO::MYSQL_ATTR_SSL_CA] = $ca;
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;

            return $options;
        }

        // TLS without a CA bundle: encrypted, but the certificate is not
        // verified. config/replication.php says so, and so does the pair form.
        if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
        }

        return $options;
    }

    /**
     * Best effort: MariaDB honours max_statement_time, MySQL does not, and a
     * pair we cannot set it on is not a pair we should refuse to check.
     */
    protected function applyStatementTimeout(Connection $connection): void
    {
        $seconds = (int) config('replication.max_statement_time');

        if ($seconds <= 0) {
            return;
        }

        try {
            $connection->statement("SET SESSION max_statement_time = {$seconds}");
        } catch (Throwable) {
            // Not MariaDB, or not permitted. The connect timeout still applies.
        }
    }
}
