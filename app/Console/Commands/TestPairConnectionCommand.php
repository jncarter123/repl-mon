<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Endpoint;
use App\Models\ServerPair;
use App\Services\ConnectionTester;
use Illuminate\Console\Command;

class TestPairConnectionCommand extends Command
{
    protected $signature = 'replication:test {pair? : Name or id of a single pair}';

    protected $description = 'Check that both servers in each pair answer, and report which grants are missing';

    public function handle(ConnectionTester $tester): int
    {
        $pairs = ServerPair::query()
            ->when($this->argument('pair'), fn ($q, $pair) => $q->where(fn ($w) => $w->where('name', $pair)->orWhere('id', $pair)))
            ->orderBy('name')
            ->get();

        if ($pairs->isEmpty()) {
            $this->components->warn('No matching pairs.');

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($pairs as $pair) {
            $this->components->info($pair->name);

            foreach (Endpoint::cases() as $endpoint) {
                $result = $tester->test($pair, $endpoint);

                if (! $result['ok']) {
                    $failed++;
                }

                $this->components->twoColumnDetail(
                    "  {$endpoint->label()}",
                    $result['ok'] ? "<fg=green>{$result['message']}</>" : "<fg=red>{$result['message']}</>",
                );

                $this->components->twoColumnDetail(
                    '  Heartbeat table',
                    $result['heartbeat_table']
                        ? '<fg=green>present</>'
                        : '<fg=yellow>missing — run replication:install-heartbeat</>',
                );

                if ($endpoint === Endpoint::Replica && $result['status_message'] !== null) {
                    $this->components->twoColumnDetail(
                        '  Replica status',
                        $result['status_readable'] ? "<fg=green>{$result['status_message']}</>" : "<fg=yellow>{$result['status_message']}</>",
                    );
                }
            }

            $this->newLine();
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
