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
    /**
     * A connection with the pair's own schema selected — what the monitor uses
     * for everything it does minute to minute.
     */
    public function connection(ServerPair $pair, Endpoint $endpoint): Connection
    {
        return $this->open($pair, $endpoint, $this->connectionName($pair, $endpoint), []);
    }

    /**
     * A connection to the server rather than to the pair's schema, for the one
     * job that cannot use the connection above: creating that schema.
     *
     * You cannot CREATE DATABASE `repl_monitor` over a connection whose DSN says
     * `dbname=repl_monitor`, because the connect fails before the statement ever
     * runs. This one selects `information_schema`, which exists on every server
     * and which every user may open — neither of which is reliably true of an
     * empty database name.
     */
    public function serverConnection(ServerPair $pair, Endpoint $endpoint): Connection
    {
        return $this->open($pair, $endpoint, $this->connectionName($pair, $endpoint, true), [
            'database' => 'information_schema',
        ]);
    }

    public function forget(ServerPair $pair): void
    {
        foreach (Endpoint::cases() as $endpoint) {
            foreach ([false, true] as $serverLevel) {
                $name = $this->connectionName($pair, $endpoint, $serverLevel);

                DB::purge($name);
                Config::set("database.connections.{$name}", null);
            }
        }
    }

    public function connectionName(ServerPair $pair, Endpoint $endpoint, bool $serverLevel = false): string
    {
        return 'pair_'.($pair->getKey() ?? 'draft').'_'.$endpoint->value.($serverLevel ? '_server' : '');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function open(ServerPair $pair, Endpoint $endpoint, string $name, array $overrides): Connection
    {
        Config::set("database.connections.{$name}", [...$this->configFor($pair, $endpoint), ...$overrides]);

        // Purge first: a pair edited in the UI must not keep talking to the
        // host it named a minute ago.
        DB::purge($name);

        $connection = DB::connection($name);

        $this->applyStatementTimeout($connection);

        return $connection;
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
