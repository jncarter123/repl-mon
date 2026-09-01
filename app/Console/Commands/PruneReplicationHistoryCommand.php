<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ReplicationAlert;
use App\Models\ReplicationCheck;
use Illuminate\Console\Command;

class PruneReplicationHistoryCommand extends Command
{
    protected $signature = 'replication:prune
                            {--checks= : Days of check history to keep}
                            {--alerts= : Days of alert history to keep}';

    protected $description = 'Trim the check history, keeping alerts far longer than the minute-by-minute samples';

    public function handle(): int
    {
        $checkDays = (int) ($this->option('checks') ?? config('replication.retain_checks_days'));
        $alertDays = (int) ($this->option('alerts') ?? config('replication.retain_alerts_days'));

        $checks = ReplicationCheck::query()
            ->where('checked_at', '<', now()->subDays($checkDays))
            ->delete();

        $alerts = ReplicationAlert::query()
            ->where('sent_at', '<', now()->subDays($alertDays))
            ->delete();

        $this->components->info("Pruned {$checks} checks older than {$checkDays} days and {$alerts} alerts older than {$alertDays} days.");

        return self::SUCCESS;
    }
}
