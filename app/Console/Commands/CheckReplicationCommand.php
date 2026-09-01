<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ServerPair;
use App\Services\ReplicationChecker;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class CheckReplicationCommand extends Command
{
    protected $signature = 'replication:check
                            {pair? : Name or id of a single pair to check}
                            {--include-disabled : Check pairs that are switched off too}';

    protected $description = 'Write a heartbeat on each primary, read it back off each replica, and alert on lag or a stopped replica';

    public function handle(ReplicationChecker $checker): int
    {
        $pairs = $this->pairs();

        if ($pairs->isEmpty()) {
            $this->components->warn('No pairs to check.');

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($pairs as $pair) {
            try {
                $check = $checker->check($pair);

                $this->components->twoColumnDetail(
                    $pair->name,
                    sprintf('<fg=%s>%s</> %s', $this->tint($check->status->color()), $check->status->label(), $check->message),
                );
            } catch (Throwable $e) {
                // One unhealthy pair must not stop the rest of the run — the
                // next pair in the list may be the one actually on fire.
                $failed++;

                Log::error('Replication check failed.', [
                    'server_pair_id' => $pair->getKey(),
                    'exception' => $e->getMessage(),
                ]);

                $this->components->error("{$pair->name}: {$e->getMessage()}");
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return Collection<int, ServerPair>
     */
    protected function pairs(): Collection
    {
        $query = ServerPair::query()->orderBy('name');

        if (! $this->option('include-disabled')) {
            $query->enabled();
        }

        if ($name = $this->argument('pair')) {
            $query->where(fn ($q) => $q->where('name', $name)->orWhere('id', $name));
        }

        return $query->get();
    }

    protected function tint(string $color): string
    {
        return match ($color) {
            'green' => 'green',
            'amber' => 'yellow',
            'red' => 'red',
            default => 'gray',
        };
    }
}
