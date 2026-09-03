<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\IncidentSummary;
use App\Models\ReplicationCheck;
use App\Models\ServerPair;
use Carbon\CarbonImmutable;

/**
 * Reads the pair's own check history back far enough to describe the episode
 * an alert is about. It never touches a monitored server — the history in this
 * app's store is the whole source, which is what makes it safe to do while an
 * outage is in progress.
 *
 * The episode is reconstructed from the checks rather than from the pair's
 * `failing_since`, because by the time a recovery alert is sent that column has
 * already been cleared — and the alert that most needs the story is exactly the
 * one sent after the problem went away.
 */
class IncidentSummariser
{
    public function summarise(ServerPair $pair, ReplicationCheck $check): ?IncidentSummary
    {
        return $this->summariseAt($pair, $check->checked_at);
    }

    /**
     * The same walk, from a moment rather than from a check — for an alert
     * whose own check has since been pruned, which is most of them by the time
     * anybody asks.
     */
    public function summariseAt(ServerPair $pair, CarbonImmutable $at): ?IncidentSummary
    {
        $checks = $pair->checks()
            ->where('checked_at', '<=', $at)
            ->orderByDesc('checked_at')
            ->orderByDesc('id')
            ->limit(max(1, (int) config('replication.incident_lookback_checks', 1440)))
            ->get();

        return IncidentSummary::fromChecks($checks, $at);
    }
}
