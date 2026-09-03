<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\CheckStatus;
use App\Models\ReplicationCheck;
use App\Support\Duration;
use Carbon\CarbonImmutable;

/**
 * What the run of failing checks behind one alert actually looked like.
 *
 * The alert row on its own answers "you were told at 02:15"; it does not
 * answer "what was wrong", and a recovery email is worse still — its check is
 * the healthy one, so the message on it says everything is fine. That is how a
 * problem that fixed itself overnight becomes a line in a table nobody can
 * read in the morning. This is the missing half: when it started, how long it
 * lasted, what it looked like at the time, and the worst it got.
 *
 * Pure, like ReplicationEvaluator and for the same reason — every branch is
 * worth a test and none of them should need a MariaDB.
 */
final readonly class IncidentSummary
{
    /**
     * @param  array<string, int>  $statusCounts  Status value => number of checks, worst first.
     * @param  ?string  $replicaError  The replica's own last-error text, taken from the
     *                                 earliest check that carried one: the cause, not
     *                                 whatever it had turned into by the end.
     * @param  bool  $startedBeforeWindow  No healthy check was found before the run — the
     *                                     history was pruned, the lookback ran out, or the
     *                                     pair was already failing when it was added. The
     *                                     start is a lower bound, and everything downstream
     *                                     says "at least" rather than reporting the edge of
     *                                     what we can see as the moment it began.
     */
    public function __construct(
        public CarbonImmutable $startedAt,
        public CarbonImmutable $lastFailureAt,
        public int $durationSeconds,
        public int $failedChecks,
        public CheckStatus $worstStatus,
        public ?float $peakLagSeconds,
        public string $firstFailureMessage,
        public array $statusCounts,
        public ?string $replicaError = null,
        public bool $startedBeforeWindow = false,
    ) {}

    /**
     * Walk back from the newest check over the run of failing ones. The list
     * is newest-first and includes the check being alerted on, which for a
     * recovery is healthy — so leading healthy checks are skipped, and the
     * episode is the block of problems immediately before them.
     *
     * @param  iterable<ReplicationCheck>  $newestFirst
     * @param  CarbonImmutable  $asOf  When the alert is being sent; the far end of the duration.
     */
    public static function fromChecks(iterable $newestFirst, CarbonImmutable $asOf): ?self
    {
        /** @var list<ReplicationCheck> $episode */
        $episode = [];
        $started = false;
        $sawHealthyStart = false;

        foreach ($newestFirst as $check) {
            if (! $check->status->isProblem()) {
                // Healthy checks before the episode (the recovery itself) are
                // skipped; one after it is where the outage began.
                if ($started) {
                    $sawHealthyStart = true;

                    break;
                }

                continue;
            }

            $started = true;
            $episode[] = $check;
        }

        if ($episode === []) {
            return null;
        }

        $oldest = $episode[array_key_last($episode)];
        $newest = $episode[0];

        $counts = [];
        $peak = null;
        $replicaError = null;

        foreach ($episode as $check) {
            $counts[$check->status->value] = ($counts[$check->status->value] ?? 0) + 1;

            if ($check->lag_seconds !== null && ($peak === null || $check->lag_seconds > $peak)) {
                $peak = $check->lag_seconds;
            }

            // Walking newest-first, so the last one seen is the earliest.
            if ($check->replica_error !== null && $check->replica_error !== '') {
                $replicaError = $check->replica_error;
            }
        }

        uksort($counts, fn (string $a, string $b): int => CheckStatus::from($b)->severity() <=> CheckStatus::from($a)->severity());

        $worst = CheckStatus::from((string) array_key_first($counts));

        return new self(
            startedAt: $oldest->checked_at,
            lastFailureAt: $newest->checked_at,
            durationSeconds: max(0, (int) $oldest->checked_at->diffInSeconds($asOf, absolute: false)),
            failedChecks: count($episode),
            worstStatus: $worst,
            peakLagSeconds: $peak,
            firstFailureMessage: (string) ($oldest->message ?? $oldest->status->label()),
            statusCounts: $counts,
            replicaError: $replicaError,
            startedBeforeWindow: ! $sawHealthyStart,
        );
    }

    public function duration(): string
    {
        if ($this->durationSeconds < 1) {
            // One failing check with nothing before it: we know it was bad, and
            // "at least under a minute" would be worse than admitting that.
            return $this->startedBeforeWindow ? 'an unknown time' : 'under a minute';
        }

        return ($this->startedBeforeWindow ? 'at least ' : '')
            .Duration::humanize((float) $this->durationSeconds);
    }

    /**
     * "Broken for 14m — 14 failed checks", the line that goes where somebody is
     * only going to read one line.
     */
    public function headline(): string
    {
        return sprintf(
            '%s for %s — %d failed check%s',
            $this->worstStatus->label(),
            $this->duration(),
            $this->failedChecks,
            $this->failedChecks === 1 ? '' : 's',
        );
    }

    /**
     * "Broken ×12, Lagging ×2" — an outage that changed shape halfway through
     * is worth seeing as one.
     */
    public function statusBreakdown(): string
    {
        return implode(', ', array_map(
            fn (string $status, int $count): string => CheckStatus::from($status)->label().' ×'.$count,
            array_keys($this->statusCounts),
            array_values($this->statusCounts),
        ));
    }
}
