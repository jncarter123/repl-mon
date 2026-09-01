<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\CheckStatus;
use App\Enums\HealthLevel;
use App\Support\Duration;
use Carbon\CarbonImmutable;

/**
 * One pair as the health endpoint sees it: its last known state, and how long
 * ago that state was actually established.
 */
final readonly class PairHealth
{
    public function __construct(
        public string $name,
        public string $key,
        public bool $enabled,
        public CheckStatus $status,
        public HealthLevel $level,
        public bool $stale,
        public ?float $lagSeconds = null,
        public ?CarbonImmutable $lastCheckedAt = null,
        public ?int $secondsSinceCheck = null,
        public ?string $message = null,
    ) {}

    /**
     * A line for the text body. Pairs are named, because "1 broken" in an alert
     * that reaches someone's phone at 3am is half a message.
     */
    public function line(): string
    {
        if (! $this->enabled) {
            return "PAUSED: {$this->name} is not being checked";
        }

        $line = "{$this->level->label()}: {$this->name} is ".strtolower($this->status->label());

        if ($this->lagSeconds !== null && in_array($this->status, [CheckStatus::Ok, CheckStatus::Lagging], true)) {
            $line .= ', '.Duration::humanize($this->lagSeconds).' behind';
        }

        if ($this->stale) {
            $line .= ' — but that was '.$this->age().' ago, so nothing has checked it since';
        } elseif ($this->message !== null && $this->status->isProblem()) {
            $line .= ' — '.$this->message;
        }

        return $line;
    }

    public function age(): string
    {
        return $this->secondsSinceCheck === null
            ? 'never'
            : Duration::humanize((float) $this->secondsSinceCheck);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'key' => $this->key,
            'enabled' => $this->enabled,
            'status' => $this->status->value,
            'level' => $this->level->value,
            'stale' => $this->stale,
            'lag_seconds' => $this->lagSeconds,
            'last_checked_at' => $this->lastCheckedAt?->toIso8601String(),
            'seconds_since_check' => $this->secondsSinceCheck,
            'message' => $this->message,
        ];
    }
}
