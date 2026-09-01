<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AlertKind;
use App\Enums\CheckStatus;
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
            'sent_at' => 'datetime',
        ];
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
