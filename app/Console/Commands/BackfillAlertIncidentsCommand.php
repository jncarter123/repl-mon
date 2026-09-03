<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ReplicationAlert;
use App\Services\IncidentSummariser;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Fills in the episode behind alerts that were sent before the monitor started
 * recording one, reading it back out of the check history the same way a live
 * alert does.
 *
 * A one-off, but not a throwaway: it is also the way to repair the record after
 * the history is restored from a backup, or when the lookback window is widened.
 *
 * It never touches `subject`, `summary` or `recipients`. Those are the record of
 * what was actually sent that night, and a backfill that rewrites them turns the
 * one trustworthy part of an alert into a reconstruction.
 */
class BackfillAlertIncidentsCommand extends Command
{
    protected $signature = 'replication:backfill-alerts
                            {pair? : Name or id of a single pair}
                            {--all : Redo alerts that already carry incident detail}
                            {--dry-run : Report what would be written and change nothing}';

    protected $description = 'Reconstruct the outage behind past alerts from the surviving check history';

    public function handle(IncidentSummariser $incidents): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $alerts = ReplicationAlert::query()
            ->with('serverPair', 'replicationCheck')
            ->when(! $this->option('all'), fn (Builder $q) => $q->whereNull('incident_started_at'))
            ->when($this->argument('pair'), fn (Builder $q, string $pair) => $q->whereHas(
                'serverPair',
                fn (Builder $p) => $p->where('name', $pair)->orWhere('id', $pair),
            ))
            ->oldest('sent_at')
            ->get();

        if ($alerts->isEmpty()) {
            $this->components->info('No alerts to backfill.');

            return self::SUCCESS;
        }

        $filled = 0;
        $skipped = 0;

        foreach ($alerts as $alert) {
            $pair = $alert->serverPair;

            // The alert's own check is the honest anchor; its send time is the
            // fallback once that check has been pruned.
            $at = $alert->replicationCheck->checked_at ?? $alert->sent_at;

            $incident = $incidents->summariseAt($pair, $at);

            if ($incident === null) {
                $skipped++;

                $this->components->twoColumnDetail(
                    $this->describe($alert),
                    '<fg=gray>nothing left to read — '.$this->whyNot($alert, $at).'</>',
                );

                continue;
            }

            if (! $dryRun) {
                $alert->forceFill([
                    'incident_started_at' => $incident->startedAt,
                    'incident_truncated' => $incident->startedBeforeWindow,
                    'incident_duration_seconds' => $incident->durationSeconds,
                    'failed_checks' => $incident->failedChecks,
                    'worst_status' => $incident->worstStatus,
                    'peak_lag_seconds' => $incident->peakLagSeconds,
                    'first_failure_message' => $incident->firstFailureMessage,
                    'replica_error' => $incident->replicaError,
                    'status_counts' => $incident->statusCounts,
                ])->save();
            }

            $filled++;

            $this->components->twoColumnDetail(
                $this->describe($alert),
                sprintf(
                    '<fg=%s>%s</> %s',
                    $incident->worstStatus->color() === 'amber' ? 'yellow' : 'red',
                    $incident->headline(),
                    $incident->startedBeforeWindow ? '<fg=gray>(start is a lower bound)</>' : '',
                ),
            );
        }

        $this->newLine();

        $this->components->info(sprintf(
            '%s %d alert%s, %d left alone.',
            $dryRun ? 'Would fill in' : 'Filled in',
            $filled,
            $filled === 1 ? '' : 's',
            $skipped,
        ));

        if ($dryRun) {
            $this->components->warn('Dry run — nothing was written.');
        }

        return self::SUCCESS;
    }

    protected function describe(ReplicationAlert $alert): string
    {
        return sprintf(
            '%s · %s · %s',
            $alert->serverPair->name,
            $alert->sent_at->toDayDateTimeString(),
            $alert->kind->label(),
        );
    }

    /**
     * Two very different silences, and an operator chasing a missing night
     * needs to know which one this is.
     */
    protected function whyNot(ReplicationAlert $alert, CarbonImmutable $at): string
    {
        $any = $alert->serverPair->checks()->where('checked_at', '<=', $at)->exists();

        return $any
            ? 'no failing checks survive from around then'
            : 'the check history for this pair has been pruned past here';
    }
}
