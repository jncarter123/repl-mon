<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\HealthLevel;
use Carbon\CarbonImmutable;

/**
 * The whole answer to "is this monitor, and everything it watches, all right?"
 * in one object, renderable as the plain text a Nagios-style plugin expects or
 * as JSON for anything that would rather have the numbers.
 *
 * Holds the verdict; it does not reach it — see HealthReporter.
 */
final readonly class HealthReport
{
    /**
     * @param  list<PairHealth>  $pairs
     * @param  list<string>  $issues  Problems with the monitor itself, as opposed to with a pair.
     * @param  array<string, int>  $counts
     * @param  array<string, float|int|null>  $metrics
     */
    public function __construct(
        public HealthLevel $level,
        public array $pairs,
        public array $issues,
        public array $counts,
        public array $metrics,
        public CarbonImmutable $generatedAt,
    ) {}

    /**
     * The one line a paging system is going to quote. Everything that matters
     * has to survive being truncated to it.
     */
    public function summary(): string
    {
        $prefix = 'REPLICATION '.$this->level->label().' - ';

        if ($this->counts['total'] === 0) {
            return $prefix.'no pairs are configured';
        }

        if ($this->counts['enabled'] === 0) {
            return $prefix.'nothing is being monitored: every pair is paused';
        }

        $parts = array_filter([
            $this->plural($this->counts['broken'], 'broken'),
            $this->plural($this->counts['unreachable'], 'unreachable'),
            $this->plural($this->counts['lagging'], 'lagging'),
            $this->plural($this->counts['unknown'], 'not yet checked'),
            $this->plural($this->counts['ok'], 'healthy'),
        ]);

        $summary = implode(', ', $parts).' of '.$this->plural($this->counts['enabled'], 'monitored pair', 'monitored pairs');

        // Stale is counted apart from the statuses rather than alongside them:
        // a stale pair still has one of those statuses, and adding it to the
        // list would make the numbers add up to more than there are pairs.
        if ($this->counts['stale'] > 0) {
            $summary .= '; '.$this->plural($this->counts['stale'], 'not checked recently');
        }

        if ($this->counts['paused'] > 0) {
            $summary .= " ({$this->counts['paused']} paused)";
        }

        return $prefix.$summary;
    }

    /**
     * Summary first, then the detail, then perfdata after the pipe where
     * Icinga expects to find it.
     */
    public function toText(): string
    {
        $lines = [$this->summary()];

        foreach ($this->issues as $issue) {
            $lines[] = 'MONITOR: '.$issue;
        }

        foreach ($this->pairs as $pair) {
            $lines[] = $pair->line();
        }

        return implode("\n", $lines)."\n| ".$this->perfdata()."\n";
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->level->value,
            'summary' => $this->summary(),
            'generated_at' => $this->generatedAt->toIso8601String(),
            'issues' => $this->issues,
            'counts' => $this->counts,
            'metrics' => $this->metrics,
            'pairs' => array_map(fn (PairHealth $pair): array => $pair->toArray(), $this->pairs),
        ];
    }

    public function perfdata(): string
    {
        $fields = [];

        foreach ($this->counts as $name => $value) {
            $fields[] = "{$name}={$value}";
        }

        foreach ($this->metrics as $name => $value) {
            if ($value !== null) {
                $fields[] = "{$name}=".rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.').'s';
            }
        }

        return implode(' ', $fields);
    }

    private function plural(int $count, string $singular, ?string $plural = null): string
    {
        if ($count === 0) {
            return '';
        }

        return $count.' '.($count === 1 ? $singular : ($plural ?? $singular));
    }
}
