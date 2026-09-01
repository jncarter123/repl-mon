<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Endpoint;
use App\Models\ServerPair;
use App\Services\HeartbeatManager;
use App\Services\PairConnectionFactory;
use App\Support\DatabaseError;
use Illuminate\Console\Command;
use Throwable;

class InstallHeartbeatCommand extends Command
{
    protected $signature = 'replication:install-heartbeat
                            {pair? : Name or id of a single pair}
                            {--replica : Also create the table directly on the replica}';

    protected $description = 'Create the heartbeat table on each primary (replication carries it to the replica)';

    public function handle(PairConnectionFactory $connections, HeartbeatManager $heartbeats): int
    {
        $pairs = ServerPair::query()
            ->when($this->argument('pair'), fn ($q, $pair) => $q->where(fn ($w) => $w->where('name', $pair)->orWhere('id', $pair)))
            ->orderBy('name')
            ->get();

        if ($pairs->isEmpty()) {
            $this->components->warn('No matching pairs.');

            return self::SUCCESS;
        }

        $endpoints = $this->option('replica')
            ? [Endpoint::Primary, Endpoint::Replica]
            : [Endpoint::Primary];

        $failed = 0;

        foreach ($pairs as $pair) {
            foreach ($endpoints as $endpoint) {
                try {
                    $connection = $connections->connection($pair, $endpoint);
                    $heartbeats->install($connection, $pair);

                    $this->components->twoColumnDetail(
                        "{$pair->name} · {$endpoint->label()}",
                        '<fg=green>'.$heartbeats->tableFor($pair).' ready</>',
                    );
                } catch (Throwable $e) {
                    $failed++;
                    $this->components->error("{$pair->name} · {$endpoint->label()}: ".DatabaseError::describe($e, $pair));
                } finally {
                    $connections->forget($pair);
                }
            }
        }

        if (! $this->option('replica')) {
            $this->newLine();
            $this->components->info('Created on the primary only. If your replica does not replicate DDL, re-run with --replica.');
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
