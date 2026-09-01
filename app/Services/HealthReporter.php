<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\HealthReport;
use App\Data\PairHealth;
use App\Enums\CheckStatus;
use App\Enums\HealthLevel;
use App\Models\AlertRecipient;
use App\Models\ReplicationAlert;
use App\Models\ServerPair;
use App\Support\Duration;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The monitor's report on itself, for something else to watch.
 *
 * Everything else in this app answers "is replication all right?". Nothing in
 * it can answer "is the thing that answers that still running?" — a scheduler
 * that has stopped leaves a dashboard full of green badges and an empty inbox,
 * which is the worst state a monitor can be in, because it is indistinguishable
 * from good news. So this reads its own store rather than any pair's server: the
 * age of the newest check is the signal, and only an outside observer can act on
 * it.
 *
 * Reads; never writes, never touches a monitored server.
 */
class HealthReporter
{
    /**
     * A pair by name, monitor key, or id — how an Icinga service on a specific
     * host addresses the one pair it cares about.
     */
    public function find(string $key): ?ServerPair
    {
        return ServerPair::query()
            ->where(fn (Builder $q) => $q
                ->where('name', $key)
                ->orWhere('monitor_key', $key)
                ->orWhere('id', $key))
            ->first();
    }

    public function report(?ServerPair $only = null): HealthReport
    {
        $now = now();
        $staleAfter = (int) config('replication.health.stale_after_minutes');

        $pairs = $this->pairs($only);

        $health = array_values($pairs
            ->map(fn (ServerPair $pair): PairHealth => $this->pairHealth($pair, $now, $staleAfter))
            ->sortByDesc(fn (PairHealth $p): int => $p->enabled ? $p->level->severity() + 1 : 0)
            ->all());

        $issues = $this->issues($pairs, $health, $now, $staleAfter);

        $counts = $this->counts($health);

        $level = HealthLevel::worst(
            ...array_map(fn (PairHealth $p): HealthLevel => $p->enabled ? $p->level : HealthLevel::Ok, $health),
            ...array_map(fn (array $issue): HealthLevel => $issue['level'], $issues),
        );

        // Nothing to watch is not health, whichever way it happened. A monitor
        // reporting OK over an empty list is exactly the silence this endpoint
        // exists to break.
        if ($counts['enabled'] === 0) {
            $level = HealthLevel::worst($level, HealthLevel::Warning);
        }

        return new HealthReport(
            level: $level,
            pairs: $health,
            issues: array_map(fn (array $issue): string => $issue['text'], $issues),
            counts: $counts,
            metrics: $this->metrics($health),
            generatedAt: $now,
        );
    }

    /**
     * @return Collection<int, ServerPair>
     */
    protected function pairs(?ServerPair $only): Collection
    {
        $query = ServerPair::query()
            ->withCount(['recipients' => fn (Builder $q) => $q->where('enabled', true)])
            ->orderBy('name');

        if ($only !== null) {
            $query->whereKey($only->getKey());
        }

        return $query->get();
    }

    protected function pairHealth(ServerPair $pair, CarbonImmutable $now, int $staleAfter): PairHealth
    {
        $checkedAt = $pair->last_checked_at;

        $since = $checkedAt === null ? null : (int) round($checkedAt->diffInSeconds($now));

        // Never checked is not stale — it is "not yet", and reads as a warning
        // through the pair's own Unknown status. Stale means the checks were
        // running and then stopped, which is a different and worse thing.
        $stale = $pair->enabled
            && $since !== null
            && $since > $staleAfter * 60;

        $level = HealthLevel::forCheckStatus($pair->current_status);

        return new PairHealth(
            name: $pair->name,
            key: $pair->monitor_key,
            enabled: $pair->enabled,
            status: $pair->current_status,
            level: $stale ? HealthLevel::Critical : $level,
            stale: $stale,
            lagSeconds: $pair->last_lag_seconds,
            lastCheckedAt: $checkedAt,
            secondsSinceCheck: $since,
            message: $pair->last_message,
        );
    }

    /**
     * Failures of the monitor rather than of a pair. Each one is a way for a
     * real outage to go unreported, which makes them worth the same attention
     * as the outage itself.
     *
     * @param  Collection<int, ServerPair>  $pairs
     * @param  list<PairHealth>  $health
     * @return list<array{level: HealthLevel, text: string}>
     */
    protected function issues(Collection $pairs, array $health, CarbonImmutable $now, int $staleAfter): array
    {
        $issues = [];

        $enabled = $pairs->where('enabled', true);

        if ($pairs->isEmpty()) {
            $issues[] = [
                'level' => HealthLevel::Warning,
                'text' => 'No pairs are configured, so nothing is being watched.',
            ];
        } elseif ($enabled->isEmpty()) {
            $issues[] = [
                'level' => HealthLevel::Warning,
                'text' => count($health) === 1
                    ? $health[0]->name.' is paused, so it is not being checked.'
                    : 'Every pair is paused, so nothing is being watched.',
            ];
        }

        $stale = array_filter($health, fn (PairHealth $p): bool => $p->stale);

        if ($stale !== []) {
            $oldest = max(array_map(fn (PairHealth $p): int => $p->secondsSinceCheck ?? 0, $stale));

            $issues[] = [
                'level' => HealthLevel::Critical,
                'text' => count($stale).' of '.$enabled->count().' monitored pairs have not been checked for up to '
                    .Duration::humanize((float) $oldest).' (limit '.$staleAfter.'m). '
                    .'The scheduler is not running `replication:check`, so every status below is older than it looks.',
            ];
        }

        if ($this->globalRecipients() === 0) {
            $orphans = $enabled->where('recipients_count', 0)->pluck('name')->all();

            if ($orphans !== []) {
                $issues[] = [
                    'level' => HealthLevel::Warning,
                    'text' => 'Nobody would be emailed about '.implode(', ', $orphans)
                        .': the pair has no recipients of its own and the global list is empty.',
                ];
            }
        }

        $undelivered = $this->undeliveredAlerts($pairs, $now);

        if ($undelivered->isNotEmpty()) {
            $issues[] = [
                'level' => HealthLevel::Critical,
                'text' => $undelivered->count().' alert(s) could not be delivered in the last '
                    .(int) config('replication.health.delivery_failure_window_minutes').'m: '
                    .$undelivered->pluck('delivery_error')->unique()->take(3)->implode('; '),
            ];
        }

        return $issues;
    }

    protected function globalRecipients(): int
    {
        return AlertRecipient::query()->global()->where('enabled', true)->count();
    }

    /**
     * @param  Collection<int, ServerPair>  $pairs
     * @return Collection<int, ReplicationAlert>
     */
    protected function undeliveredAlerts(Collection $pairs, CarbonImmutable $now): Collection
    {
        $window = (int) config('replication.health.delivery_failure_window_minutes');

        if ($window <= 0) {
            return new Collection;
        }

        return ReplicationAlert::query()
            ->whereNotNull('delivery_error')
            ->where('sent_at', '>=', $now->subMinutes($window))
            ->whereIn('server_pair_id', $pairs->modelKeys())
            ->orderByDesc('sent_at')
            ->limit(20)
            ->get();
    }

    /**
     * @param  list<PairHealth>  $health
     * @return array<string, int>
     */
    protected function counts(array $health): array
    {
        $enabled = array_filter($health, fn (PairHealth $p): bool => $p->enabled);

        $withStatus = fn (CheckStatus $status): int => count(array_filter(
            $enabled,
            fn (PairHealth $p): bool => $p->status === $status,
        ));

        return [
            'total' => count($health),
            'enabled' => count($enabled),
            'paused' => count($health) - count($enabled),
            'ok' => $withStatus(CheckStatus::Ok),
            'lagging' => $withStatus(CheckStatus::Lagging),
            'broken' => $withStatus(CheckStatus::Broken),
            'unreachable' => $withStatus(CheckStatus::Unreachable),
            'unknown' => $withStatus(CheckStatus::Unknown),
            'stale' => count(array_filter($enabled, fn (PairHealth $p): bool => $p->stale)),
        ];
    }

    /**
     * @param  list<PairHealth>  $health
     * @return array<string, float|int|null>
     */
    protected function metrics(array $health): array
    {
        $enabled = array_filter($health, fn (PairHealth $p): bool => $p->enabled);

        $lags = array_filter(array_map(fn (PairHealth $p): ?float => $p->lagSeconds, $enabled), is_float(...));
        $ages = array_filter(array_map(fn (PairHealth $p): ?int => $p->secondsSinceCheck, $enabled), is_int(...));

        return [
            'max_lag' => $lags === [] ? null : max($lags),
            'oldest_check' => $ages === [] ? null : max($ages),
        ];
    }
}
