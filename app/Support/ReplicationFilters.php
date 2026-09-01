<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The four `*_db` replication filters, and the one question worth asking of
 * them: is this schema excluded, and by which setting?
 *
 * A heartbeat schema that has been filtered out is the nastiest way to set this
 * app up wrong. Everything connects, the table gets created, the beat is written
 * — and it never arrives, so the monitor reports the pair broken, correctly and
 * forever. It looks exactly like a real outage. Catching it at setup time is
 * worth reading four variables for.
 *
 * Pure: hand it the values, it answers. No connection, no clock.
 */
final readonly class ReplicationFilters
{
    /** Read from the primary — whether the write is written to the binlog at all. */
    public const PRIMARY_VARIABLES = ['binlog_do_db', 'binlog_ignore_db'];

    /** Read from the replica — whether the replica applies it once it arrives. */
    public const REPLICA_VARIABLES = [
        'replicate_do_db',
        'replicate_ignore_db',
        'replicate_wild_do_table',
        'replicate_wild_ignore_table',
    ];

    /**
     * @param  array<string, string>  $primary  Variable name => raw value.
     * @param  array<string, string>  $replica  Variable name => raw value.
     */
    public function __construct(
        private array $primary = [],
        private array $replica = [],
    ) {}

    /**
     * Why this schema cannot get across, or null if these settings do not stand
     * in its way.
     *
     * Ordered primary-first: if the write never reaches the binlog, what the
     * replica would have done with it is beside the point.
     */
    public function excludes(string $schema): ?string
    {
        $allowed = $this->list($this->primary, 'binlog_do_db');

        if ($allowed !== [] && ! $this->contains($allowed, $schema)) {
            return "The primary's `binlog_do_db` is set to ".$this->render($allowed)
                .", so writes to `{$schema}` are never written to the binary log.";
        }

        $ignored = $this->list($this->primary, 'binlog_ignore_db');

        if ($this->contains($ignored, $schema)) {
            return "The primary's `binlog_ignore_db` lists `{$schema}`, so writes to it are never written to the binary log.";
        }

        $allowed = $this->list($this->replica, 'replicate_do_db');

        if ($allowed !== [] && ! $this->contains($allowed, $schema)) {
            return "The replica's `replicate_do_db` is set to ".$this->render($allowed)
                .", so it discards changes to `{$schema}`.";
        }

        $ignored = $this->list($this->replica, 'replicate_ignore_db');

        if ($this->contains($ignored, $schema)) {
            return "The replica's `replicate_ignore_db` lists `{$schema}`, so it discards changes to it.";
        }

        return null;
    }

    /**
     * Wildcard table filters that are set to something. Deliberately reported
     * rather than evaluated: matching MariaDB's wildcard rules properly is a job
     * in itself, and an operator who is told the setting is non-empty has enough
     * to go and look at it.
     *
     * @return array<string, string>
     */
    public function wildcards(): array
    {
        $set = [];

        foreach (['replicate_wild_do_table', 'replicate_wild_ignore_table'] as $name) {
            $value = trim($this->replica[$name] ?? '');

            if ($value !== '') {
                $set[$name] = $value;
            }
        }

        return $set;
    }

    /**
     * Did we manage to read anything at all? If both servers gave us nothing
     * there is no point reporting "no filters found" as though it were a
     * finding.
     */
    public function wereRead(): bool
    {
        return $this->primary !== [] || $this->replica !== [];
    }

    /**
     * @param  array<string, string>  $variables
     * @return list<string>
     */
    private function list(array $variables, string $name): array
    {
        $value = trim($variables[$name] ?? '');

        if ($value === '') {
            return [];
        }

        return array_values(array_filter(
            array_map(trim(...), explode(',', $value)),
            fn (string $entry): bool => $entry !== '',
        ));
    }

    /**
     * Compared case-insensitively. Whether schema names are case-sensitive
     * depends on the server's `lower_case_table_names`, and this text is a
     * diagnosis rather than a decision — pointing at a filter that differs only
     * in case is far more useful than silently missing it.
     *
     * @param  list<string>  $entries
     */
    private function contains(array $entries, string $schema): bool
    {
        foreach ($entries as $entry) {
            if (strcasecmp($entry, $schema) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $entries
     */
    private function render(array $entries): string
    {
        return '`'.implode('`, `', $entries).'`';
    }
}
