<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\ProvisionReport;
use App\Data\ProvisionStep;
use App\Enums\Endpoint;
use App\Enums\ProvisionOutcome;
use App\Models\ServerPair;
use App\Support\DatabaseError;
use App\Support\Duration;
use App\Support\GrantAdvice;
use App\Support\ReplicationFilters;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PDOException;
use Throwable;

/**
 * Sets a pair up: create the heartbeat schema, create the table in it, then
 * prove the pair actually carries a beat from one side to the other.
 *
 * The third step is the point of the whole thing. Everything up to it can
 * succeed on a pair whose heartbeat schema is filtered out of replication, and
 * the result looks identical to a real outage a minute later, forever. Proving
 * it at setup time is the difference between a monitor and a red light nobody
 * can explain.
 *
 * Assumes replication itself is already configured — this never issues
 * CHANGE MASTER, never alters a replication filter, and never drops anything.
 * Its only DDL is CREATE ... IF NOT EXISTS.
 *
 * Acts and reports. It writes no models, decides nothing about alerting, and
 * hands back a ProvisionReport for the caller to render.
 */
class HeartbeatProvisioner
{
    /** MariaDB's "access denied" family, in the order you meet them. */
    private const DENIAL_CODES = [1044, 1045, 1142, 1143, 1227, 1370];

    public function __construct(
        protected PairConnectionFactory $connections,
        protected HeartbeatManager $heartbeats,
        protected ReplicaStatusReader $status,
    ) {}

    /**
     * Every step, in order. A step that cannot be attempted because the one
     * before it failed is reported Skipped rather than silently dropped.
     */
    public function provision(ServerPair $pair, bool $includeReplica = false): ProvisionReport
    {
        try {
            $report = new ProvisionReport;

            $endpoints = $includeReplica
                ? [Endpoint::Primary, Endpoint::Replica]
                : [Endpoint::Primary];

            foreach ($endpoints as $endpoint) {
                $report = $report->with($schema = $this->schemaStep($pair, $endpoint));

                $report = $report->with($schema->isSuccess()
                    ? $this->tableStep($pair, $endpoint)
                    : new ProvisionStep(
                        $this->label('Heartbeat table', $endpoint),
                        ProvisionOutcome::Skipped,
                        'Not attempted — the schema it lives in is not there.',
                    ));
            }

            return $report->with($report->isSuccess()
                ? $this->verifyStep($pair)
                : new ProvisionStep(
                    'Replication',
                    ProvisionOutcome::Skipped,
                    'Not attempted — there is nothing to carry across yet.',
                ));
        } finally {
            $this->connections->forget($pair);
        }
    }

    /**
     * The verification on its own, for a pair that is already set up.
     */
    public function verify(ServerPair $pair): ProvisionReport
    {
        try {
            return (new ProvisionReport)->with($this->verifyStep($pair));
        } finally {
            $this->connections->forget($pair);
        }
    }

    protected function schemaStep(ServerPair $pair, Endpoint $endpoint): ProvisionStep
    {
        $label = $this->label('Heartbeat schema', $endpoint);

        try {
            $schema = $this->heartbeats->schemaFor($pair, $endpoint);
        } catch (InvalidArgumentException $e) {
            return new ProvisionStep($label, ProvisionOutcome::Failed, $e->getMessage()
                .' A schema this app can create must be letters, numbers and underscores only.');
        }

        try {
            $connection = $this->connections->serverConnection($pair, $endpoint);

            if ($this->heartbeats->schemaExists($connection, $pair, $endpoint)) {
                return new ProvisionStep($label, ProvisionOutcome::AlreadyPresent, "`{$schema}` is already there.");
            }

            $this->heartbeats->createSchema($connection, $pair, $endpoint);

            return new ProvisionStep($label, ProvisionOutcome::Created, "Created `{$schema}`.");
        } catch (Throwable $e) {
            return $this->failure($label, $e, $pair, $endpoint, "Could not create `{$schema}`");
        }
    }

    protected function tableStep(ServerPair $pair, Endpoint $endpoint): ProvisionStep
    {
        $label = $this->label('Heartbeat table', $endpoint);
        $table = $this->heartbeats->tableFor($pair);

        try {
            $connection = $this->connections->connection($pair, $endpoint);

            if ($this->heartbeats->tableExists($connection, $pair)) {
                return new ProvisionStep($label, ProvisionOutcome::AlreadyPresent, "`{$table}` is already there.");
            }

            $this->heartbeats->install($connection, $pair);

            return new ProvisionStep($label, ProvisionOutcome::Created, "Created `{$table}`.");
        } catch (Throwable $e) {
            return $this->failure($label, $e, $pair, $endpoint, "Could not create `{$table}`");
        }
    }

    /**
     * Write a beat on the primary and wait for it on the replica. This is the
     * same measurement the monitor makes every minute, given a longer rope.
     */
    protected function verifyStep(ServerPair $pair): ProvisionStep
    {
        $label = 'Replication';
        $budgetMs = $this->verifyBudgetMs();
        $waited = Duration::humanize($budgetMs / 1000);

        try {
            $primary = $this->connections->connection($pair, Endpoint::Primary);
            $beat = $this->heartbeats->writeBeat($primary, $pair);
        } catch (Throwable $e) {
            return new ProvisionStep($label, ProvisionOutcome::Failed,
                'Could not write a beat on the primary: '.DatabaseError::describe($e, $pair));
        }

        try {
            $replica = $this->connections->connection($pair, Endpoint::Replica);
            $seen = $this->heartbeats->awaitBeat($replica, $pair, $beat['number'], $budgetMs);
        } catch (Throwable $e) {
            return new ProvisionStep($label, ProvisionOutcome::NotArrived,
                'The beat was written, but could not be read back off the replica: '
                .DatabaseError::describe($e, $pair).' '.$this->diagnose($pair));
        }

        if ($seen !== null && $seen['number'] >= $beat['number']) {
            $lag = max(0.0, round(
                (CarbonImmutable::now('UTC')->getPreciseTimestamp(3) - $seen['at']->getPreciseTimestamp(3)) / 1000,
                3
            ));

            return new ProvisionStep($label, ProvisionOutcome::Verified,
                'The beat reached the replica in '.Duration::humanize($lag).'. This pair is ready to monitor.');
        }

        return new ProvisionStep($label, ProvisionOutcome::NotArrived,
            "The beat was written on the primary and had not reached the replica after {$waited}. ".$this->diagnose($pair));
    }

    /**
     * Why the beat did not get across. Answered in the order that makes the
     * next question worth asking: if the table itself never arrived, the filters
     * explain it; if the filters are innocent, the threads are the suspect.
     *
     * Everything read here is readable without a special grant, so a shop that
     * will not hand out REPLICA MONITOR still gets the first two answers.
     */
    protected function diagnose(ServerPair $pair): string
    {
        $schema = $pair->replica_database;

        try {
            $replica = $this->connections->serverConnection($pair, Endpoint::Replica);

            if (! $this->heartbeats->schemaExists($replica, $pair, Endpoint::Replica)) {
                return rtrim("The schema `{$schema}` does not exist on the replica at all, so the CREATE DATABASE never "
                    .'got there. Either it is filtered out of replication, or this pair does not replicate DDL — in '
                    .'which case re-run including the replica. '
                    .$this->filterSentence($pair));
            }

            if (! $this->heartbeats->tableExistsIn($replica, $pair, Endpoint::Replica)) {
                return rtrim('The heartbeat table has not reached the replica, so DDL is not crossing. Either the schema is '
                    .'filtered out of replication, or this pair does not replicate DDL — in which case re-run including '
                    .'the replica. '.$this->filterSentence($pair));
            }
        } catch (Throwable $e) {
            return 'The replica could not be inspected to find out why: '.DatabaseError::describe($e, $pair);
        }

        // The table is there, so DDL crossed and the schema is not filtered on
        // the DDL path. Ask the filters anyway — a row can be filtered where the
        // statement that made the table was not — then ask the threads.
        $filters = $this->readFilters($pair);
        $excluded = $filters->excludes((string) $schema);

        if ($excluded !== null) {
            return $excluded;
        }

        try {
            $status = $this->status->read(
                $this->connections->connection($pair, Endpoint::Replica),
                $pair
            );

            if (($status['not_a_replica'] ?? false) === true) {
                return 'The replica reports that it is not replicating from anything. Somebody needs to point it at the primary.';
            }

            $stopped = $this->status->stoppedThread($status);

            if ($stopped !== null) {
                $error = $status['error'] ?? null;

                return $stopped.(is_string($error) && $error !== '' ? " It reports: {$error}" : '');
            }
        } catch (Throwable) {
            // Not being allowed to read replication status is not a fault here
            // any more than it is during a check. Fall through.
        }

        $wildcards = $filters->wildcards();

        if ($wildcards !== []) {
            $rendered = [];

            foreach ($wildcards as $name => $value) {
                $rendered[] = "`{$name}` = `{$value}`";
            }

            return 'The threads are running and no schema filter excludes this schema, but the replica has '
                .implode(' and ', $rendered).' set. Check that the heartbeat table is not matched by it.';
        }

        return 'The threads are running and nothing obvious excludes this schema, so the replica is most likely just '
            .'behind. Give it a moment and verify again.';
    }

    protected function filterSentence(ServerPair $pair): string
    {
        $excluded = $this->readFilters($pair)->excludes((string) $pair->replica_database);

        return $excluded ?? '';
    }

    /**
     * SHOW GLOBAL VARIABLES on both sides. Every value read here is world
     * readable, so this works with the monitor's own minimal credentials.
     */
    protected function readFilters(ServerPair $pair): ReplicationFilters
    {
        return new ReplicationFilters(
            $this->variables($pair, Endpoint::Primary, ReplicationFilters::PRIMARY_VARIABLES),
            $this->variables($pair, Endpoint::Replica, ReplicationFilters::REPLICA_VARIABLES),
        );
    }

    /**
     * @param  list<string>  $wanted
     * @return array<string, string>
     */
    protected function variables(ServerPair $pair, Endpoint $endpoint, array $wanted): array
    {
        try {
            // No LIKE pattern: SHOW GLOBAL VARIABLES takes no placeholder, and
            // interpolating a name into it is not a thing worth doing to save
            // one round trip.
            $rows = $this->connections->serverConnection($pair, $endpoint)->select('SHOW GLOBAL VARIABLES');
        } catch (Throwable) {
            return [];
        }

        $found = [];

        foreach ($rows as $row) {
            $row = array_change_key_case((array) $row);
            $name = strtolower((string) ($row['variable_name'] ?? ''));

            if (in_array($name, $wanted, true)) {
                $found[$name] = (string) ($row['value'] ?? '');
            }
        }

        return $found;
    }

    /**
     * A refusal is not a fault. The monitor's credentials are deliberately
     * small, and the answer is a GRANT for somebody who has the rights to give
     * one — not a prompt asking this operator for a root password.
     */
    protected function failure(string $label, Throwable $e, ServerPair $pair, Endpoint $endpoint, string $prefix): ProvisionStep
    {
        $message = DatabaseError::describe($e, $pair);

        if (! $this->isDenial($e)) {
            return new ProvisionStep($label, ProvisionOutcome::Failed, "{$prefix}: {$message}");
        }

        return new ProvisionStep(
            $label,
            ProvisionOutcome::Denied,
            "{$prefix}: {$message} The monitor's own credentials are not allowed to. Hand this to somebody who is:",
            GrantAdvice::forSchema($pair, $endpoint),
        );
    }

    protected function isDenial(Throwable $e): bool
    {
        if ($e instanceof PDOException && is_array($e->errorInfo)) {
            $code = $e->errorInfo[1] ?? null;

            if (is_int($code) && in_array($code, self::DENIAL_CODES, true)) {
                return true;
            }
        }

        return str_contains(strtolower($e->getMessage()), 'denied');
    }

    protected function verifyBudgetMs(): int
    {
        return max(0, (int) config('replication.provision_verify_timeout_ms'));
    }

    protected function label(string $what, Endpoint $endpoint): string
    {
        return "{$what} · {$endpoint->label()}";
    }
}
