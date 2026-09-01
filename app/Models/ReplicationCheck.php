<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CheckStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ReplicationCheckFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $server_pair_id
 * @property CheckStatus $status
 * @property bool $primary_reachable
 * @property bool $replica_reachable
 * @property float|null $lag_seconds
 * @property CarbonImmutable|null $beat_written_at
 * @property CarbonImmutable|null $beat_seen_at
 * @property string|null $io_running
 * @property string|null $sql_running
 * @property int|null $seconds_behind_source
 * @property string|null $replica_error
 * @property string|null $status_query_error
 * @property string|null $message
 * @property int|null $duration_ms
 * @property CarbonImmutable $checked_at
 * @property-read ServerPair $serverPair
 */
class ReplicationCheck extends Model
{
    /** @use HasFactory<ReplicationCheckFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CheckStatus::class,
            'primary_reachable' => 'boolean',
            'replica_reachable' => 'boolean',
            'lag_seconds' => 'float',
            'beat_written_at' => 'datetime',
            'beat_seen_at' => 'datetime',
            'seconds_behind_source' => 'integer',
            'duration_ms' => 'integer',
            'checked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ServerPair, $this>
     */
    public function serverPair(): BelongsTo
    {
        return $this->belongsTo(ServerPair::class);
    }
}
