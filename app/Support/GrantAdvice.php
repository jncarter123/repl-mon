<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\Endpoint;
use App\Models\ServerPair;

/**
 * When the monitor's own credentials are not allowed to create the heartbeat
 * schema, this app does not ask anyone to paste in a root password. It prints
 * what a DBA needs to run and gets out of the way.
 *
 * Pure, and — deliberately — never renders a password. The output of this class
 * is shown on screen and copied into tickets and chat windows.
 */
final readonly class GrantAdvice
{
    /**
     * The privileges the monitor actually uses, per side. Nothing here needs
     * write access to anybody's data, and nothing needs SUPER.
     */
    private const PRIVILEGES = [
        'primary' => 'SELECT, INSERT, UPDATE, CREATE',
        'replica' => 'SELECT',
    ];

    /**
     * SQL that creates the heartbeat schema and grants this pair's user what it
     * needs on it.
     */
    public static function forSchema(ServerPair $pair, Endpoint $endpoint): string
    {
        $schema = self::identifier((string) $pair->{"{$endpoint->value}_database"});
        $user = self::literal((string) $pair->{"{$endpoint->value}_username"});
        $privileges = self::PRIVILEGES[$endpoint->value];

        $sql = <<<SQL
            CREATE DATABASE IF NOT EXISTS `{$schema}` DEFAULT CHARACTER SET utf8mb4;
            GRANT {$privileges} ON `{$schema}`.* TO '{$user}'@'<host this app connects from>';
            SQL;

        if ($endpoint === Endpoint::Replica) {
            // The other half of the replica's grants, so a DBA reading this only
            // has to be asked once.
            $sql .= <<<SQL

                -- Also, so the monitor can read SHOW REPLICA STATUS:
                GRANT REPLICA MONITOR ON *.* TO '{$user}'@'<host this app connects from>';       -- MariaDB 10.5.9+
                -- GRANT REPLICATION CLIENT ON *.* TO '{$user}'@'<host this app connects from>'; -- older MariaDB, or MySQL
                SQL;
        }

        return $sql;
    }

    /**
     * Backticks are the only thing that can break out of a backtick-quoted
     * identifier. The schema name has already been through
     * PairConnectionFactory::assertSafeIdentifier() everywhere this is reached
     * from; this is the belt to that pair of braces, because the result is text
     * somebody is likely to paste into a root shell.
     */
    private static function identifier(string $value): string
    {
        return str_replace('`', '', $value);
    }

    private static function literal(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }
}
