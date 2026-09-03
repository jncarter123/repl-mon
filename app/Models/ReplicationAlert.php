<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AlertKind;
use App\Enums\CheckStatus;
use App\Support\Duration;
use Carbon\CarbonImmutable;
use Database\Factories\ReplicationAlertFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $server_pair_id
 * @property int|null $replication_check_id
 * @property AlertKind $kind
 * @property CheckStatus $status
 * @property string $subject
 * @property string|null $summary
 * @property list<string> $recipients
 * @property float|null $lag_seconds
 * @property CarbonImmutable|null $incident_started_at
 * @property bool $incident_truncated
 * @property int|null $incident_duration_seconds
 * @property int|null $failed_checks
 * @property CheckStatus|null $worst_status
 * @property float|null $peak_lag_seconds
 * @property string|null $first_failure_message
 * @property string|null $replica_error
 * @property array<string, int>|null $status_counts
 * @property string|null $delivery_error
 * @property CarbonImmutable $sent_at
 * @property-read ServerPair $serverPair
 * @property-read ReplicationCheck|null $replicationCheck
 */
class ReplicationAlert extends Model
{
    /** @use HasFactory<ReplicationAlertFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => AlertKind::class,
            'status' => CheckStatus::class,
            'recipients' => 'array',
            'lag_seconds' => 'float',
            'incident_started_at' => 'datetime',
            'incident_truncated' => 'boolean',
            'incident_duration_seconds' => 'integer',
            'failed_checks' => 'integer',
            'worst_status' => CheckStatus::class,
            'peak_lag_seconds' => 'float',
            'status_counts' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    /**
     * Whether this alert carries the episode behind it. Alerts written before
     * that was recorded do not, and the UI says so rather than showing a row of
     * dashes that reads like nothing happened.
     */
    public function hasIncident(): bool
    {
        return $this->incident_started_at !== null;
    }

    /**
     * The one line to show where there is room for one line: what was wrong and
     * for how long. For a recovery this is the whole point — its own check is
     * the healthy one, so `summary` says everything is fine.
     */
    public function incidentHeadline(): ?string
    {
        if (! $this->hasIncident()) {
            return null;
        }

        return sprintf(
            '%s for %s — %d failed check%s',
            $this->worst_status?->label() ?? $this->status->label(),
            $this->incidentDuration(),
            (int) $this->failed_checks,
            (int) $this->failed_checks === 1 ? '' : 's',
        );
    }

    /**
     * "at least 22m" when the episode ran off the end of the history it was
     * read from — see the `incident_truncated` column.
     */
    public function incidentDuration(): string
    {
        if (($this->incident_duration_seconds ?? 0) < 1) {
            return $this->incident_truncated ? 'an unknown time' : 'under a minute';
        }

        return ($this->incident_truncated ? 'at least ' : '')
            .Duration::humanize((float) $this->incident_duration_seconds);
    }

    /**
     * "Broken ×12, Lagging ×2".
     */
    public function statusBreakdown(): ?string
    {
        if (! is_array($this->status_counts) || $this->status_counts === []) {
            return null;
        }

        return implode(', ', array_map(
            fn (string $status, int $count): string => (CheckStatus::tryFrom($status)?->label() ?? $status).' ×'.$count,
            array_keys($this->status_counts),
            array_values($this->status_counts),
        ));
    }

    /**
     * @return BelongsTo<ServerPair, $this>
     */
    public function serverPair(): BelongsTo
    {
        return $this->belongsTo(ServerPair::class);
    }

    /**
     * @return BelongsTo<ReplicationCheck, $this>
     */
    public function replicationCheck(): BelongsTo
    {
        return $this->belongsTo(ReplicationCheck::class);
    }
}
