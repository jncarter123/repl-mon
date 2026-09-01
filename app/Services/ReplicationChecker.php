<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\ProbeResult;
use App\Enums\AlertKind;
use App\Enums\CheckStatus;
use App\Models\ReplicationCheck;
use App\Models\ServerPair;
use App\Support\DatabaseError;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One check of one pair: probe, judge, record, and decide whether anyone needs
 * to hear about it.
 */
class ReplicationChecker
{
    public function __construct(
        protected ReplicationProbe $probe,
        protected ReplicationEvaluator $evaluator,
        protected AlertDispatcher $alerts,
    ) {}

    public function check(ServerPair $pair): ReplicationCheck
    {
        try {
            $probe = $this->probe->probe($pair);
        } catch (Throwable $e) {
            // The probe already catches per-server failures; reaching here means
            // something structural (a bad table name, an unreadable credential).
            // A pair that cannot be probed is a pair whose state is unknown, and
            // that is worth an email too.
            Log::error('Replication probe failed outright.', [
                'server_pair_id' => $pair->getKey(),
                'exception' => $e->getMessage(),
            ]);

            $probe = new ProbeResult(
                primaryError: DatabaseError::describe($e, $pair),
                replicaError: DatabaseError::describe($e, $pair),
            );
        }

        $outcome = $this->evaluator->evaluate($pair, $probe);

        $check = $pair->checks()->create([
            'status' => $outcome->status,
            'primary_reachable' => $probe->primaryReachable,
            'replica_reachable' => $probe->replicaReachable,
            'lag_seconds' => $probe->lagSeconds,
            'beat_written_at' => $probe->beatWrittenAt,
            'beat_seen_at' => $probe->beatSeenAt,
            'io_running' => $probe->ioRunning,
            'sql_running' => $probe->sqlRunning,
            'seconds_behind_source' => $probe->secondsBehindSource,
            'replica_error' => $probe->replicaStatusError,
            'status_query_error' => $probe->statusQueryError,
            'message' => $outcome->message,
            'duration_ms' => $probe->durationMs,
            'checked_at' => now(),
        ]);

        $previousStatus = $pair->current_status;

        $this->applyState($pair, $check);
        $this->maybeAlert($pair, $check, $previousStatus);

        $pair->save();

        return $check;
    }

    protected function applyState(ServerPair $pair, ReplicationCheck $check): void
    {
        $now = now();
        $status = $check->status;

        if ($status->isProblem()) {
            $pair->consecutive_failures = $pair->consecutive_failures + 1;
            $pair->failing_since ??= $now;
        } else {
            $pair->consecutive_failures = 0;
            $pair->failing_since = null;
            $pair->last_ok_at = $now;
        }

        if ($pair->current_status !== $status) {
            $pair->status_changed_at = $now;
        }

        $pair->current_status = $status;
        $pair->last_checked_at = $now;
        $pair->last_lag_seconds = $check->lag_seconds;
        $pair->last_message = $check->message;
    }

    /**
     * The rules that keep a monitor from becoming a mail folder people filter
     * away:
     *
     *  - nothing until the pair has failed `failures_before_alert` times in a
     *    row, so one slow minute does not wake anyone;
     *  - one email when it starts, not one a minute;
     *  - another immediately if it gets worse (lagging → broken), because that
     *    is new information;
     *  - a reminder every `realert_after_minutes` while it stays broken, so a
     *    long outage does not go quiet;
     *  - one when it clears, so nobody drives in to check.
     */
    protected function maybeAlert(ServerPair $pair, ReplicationCheck $check, CheckStatus $previousStatus): void
    {
        $now = now();
        $status = $check->status;

        if (! $status->isProblem()) {
            if ($pair->alerting) {
                $this->alerts->send($pair, $check, AlertKind::Recovery);
                $pair->alerting = false;
                $pair->last_alert_at = $now;
            }

            return;
        }

        if ($pair->consecutive_failures < max(1, $pair->failures_before_alert)) {
            return;
        }

        if (! $pair->alerting) {
            $this->alerts->send($pair, $check, AlertKind::Problem);
            $pair->alerting = true;
            $pair->last_alert_at = $now;

            return;
        }

        $escalated = $previousStatus !== $status && $previousStatus->isProblem();

        $due = $pair->realert_after_minutes > 0
            && ($pair->last_alert_at === null
                || $pair->last_alert_at->copy()->addMinutes($pair->realert_after_minutes)->lessThanOrEqualTo($now));

        if ($escalated || $due) {
            $this->alerts->send($pair, $check, AlertKind::Problem);
            $pair->last_alert_at = $now;
        }
    }
}
