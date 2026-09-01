<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CheckStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ServerPairFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $monitor_key
 * @property string $name
 * @property string|null $description
 * @property bool $enabled
 * @property string $primary_host
 * @property int $primary_port
 * @property string $primary_username
 * @property string|null $primary_password
 * @property string $primary_database
 * @property bool $primary_use_tls
 * @property string $replica_host
 * @property int $replica_port
 * @property string $replica_username
 * @property string|null $replica_password
 * @property string $replica_database
 * @property bool $replica_use_tls
 * @property string $heartbeat_table
 * @property int $lag_threshold_seconds
 * @property bool $check_replica_status
 * @property int $failures_before_alert
 * @property int $realert_after_minutes
 * @property int $connect_timeout_seconds
 * @property CheckStatus $current_status
 * @property string|null $last_message
 * @property float|null $last_lag_seconds
 * @property CarbonImmutable|null $status_changed_at
 * @property CarbonImmutable|null $last_checked_at
 * @property CarbonImmutable|null $last_ok_at
 * @property CarbonImmutable|null $failing_since
 * @property int $consecutive_failures
 * @property CarbonImmutable|null $last_alert_at
 * @property bool $alerting
 * @property int|null $recipients_count
 * @property-read Collection<int, AlertRecipient> $recipients
 * @property-read Collection<int, ReplicationCheck> $checks
 * @property-read Collection<int, ReplicationAlert> $alerts
 * @property-read ReplicationCheck|null $latestCheck
 */
class ServerPair extends Model
{
    /** @use HasFactory<ServerPairFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(function (self $pair): void {
            $pair->monitor_key ??= (string) Str::uuid();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'primary_port' => 'integer',
            'primary_password' => 'encrypted',
            'primary_use_tls' => 'boolean',
            'replica_port' => 'integer',
            'replica_password' => 'encrypted',
            'replica_use_tls' => 'boolean',
            'lag_threshold_seconds' => 'integer',
            'check_replica_status' => 'boolean',
            'failures_before_alert' => 'integer',
            'realert_after_minutes' => 'integer',
            'connect_timeout_seconds' => 'integer',
            'current_status' => CheckStatus::class,
            'last_lag_seconds' => 'float',
            'status_changed_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'last_ok_at' => 'datetime',
            'failing_since' => 'datetime',
            'consecutive_failures' => 'integer',
            'last_alert_at' => 'datetime',
            'alerting' => 'boolean',
        ];
    }

    /**
     * @return HasMany<ReplicationCheck, $this>
     */
    public function checks(): HasMany
    {
        return $this->hasMany(ReplicationCheck::class);
    }

    /**
     * @return HasOne<ReplicationCheck, $this>
     */
    public function latestCheck(): HasOne
    {
        return $this->hasOne(ReplicationCheck::class)->latestOfMany('checked_at');
    }

    /**
     * @return HasMany<AlertRecipient, $this>
     */
    public function recipients(): HasMany
    {
        return $this->hasMany(AlertRecipient::class);
    }

    /**
     * @return HasMany<ReplicationAlert, $this>
     */
    public function alerts(): HasMany
    {
        return $this->hasMany(ReplicationAlert::class);
    }

    /**
     * Who hears about this pair: its own list if it has one, the global list
     * otherwise. A pair with its own recipients does not also get the globals —
     * naming someone specific is how you say "not the usual list".
     *
     * @return Collection<int, AlertRecipient>
     */
    public function resolvedRecipients(): Collection
    {
        $own = $this->recipients()->where('enabled', true)->orderBy('email')->get();

        if ($own->isNotEmpty()) {
            return $own;
        }

        return AlertRecipient::query()
            ->global()
            ->where('enabled', true)
            ->orderBy('email')
            ->get();
    }

    public function usesGlobalRecipients(): bool
    {
        return $this->recipients()->where('enabled', true)->doesntExist();
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeEnabled(Builder $query): void
    {
        $query->where('enabled', true);
    }

    public function primaryLabel(): string
    {
        return "{$this->primary_host}:{$this->primary_port}/{$this->primary_database}";
    }

    public function replicaLabel(): string
    {
        return "{$this->replica_host}:{$this->replica_port}/{$this->replica_database}";
    }
}
