<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Data\ProvisionReport;
use App\Models\ServerPair;
use App\Services\HeartbeatProvisioner;
use Illuminate\Console\Command;
use Throwable;

class ProvisionHeartbeatCommand extends Command
{
    protected $signature = 'replication:provision
                            {pair? : Name or id of a single pair}
                            {--replica : Also create the schema and table directly on the replica}
                            {--verify-only : Skip the creating and just prove a beat gets across}';

    protected $description = 'Create the heartbeat schema and table for a pair, then prove replication carries it';

    public function handle(HeartbeatProvisioner $provisioner): int
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
            $this->newLine();
            $this->components->info($pair->name);

            try {
                $report = $this->option('verify-only')
                    ? $provisioner->verify($pair)
                    : $provisioner->provision($pair, (bool) $this->option('replica'));
            } catch (Throwable $e) {
                // The provisioner catches per-step failures; reaching here means
                // something structural. One pair must never abort the rest.
                $this->components->error("{$pair->name}: {$e->getMessage()}");
                $failed++;

                continue;
            }

            $this->renderReport($report);

            if (! $report->isSuccess()) {
                $failed++;
            }
        }

        $this->newLine();

        if (! $this->option('replica') && ! $this->option('verify-only')) {
            $this->components->info('Created on the primary only. If your replica does not replicate DDL, re-run with --replica.');
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    protected function renderReport(ProvisionReport $report): void
    {
        foreach ($report->steps as $step) {
            $colour = $step->isSuccess() ? 'green' : ($step->outcome->color() === 'amber' ? 'yellow' : 'red');

            $this->components->twoColumnDetail($step->label, "<fg={$colour}>{$step->outcome->label()}</>");
            $this->line("  <fg=gray>{$step->message}</>");

            if ($step->remedy !== null) {
                $this->newLine();

                foreach (explode("\n", $step->remedy) as $line) {
                    $this->line("    <fg=cyan>{$line}</>");
                }

                $this->newLine();
            }
        }
    }
}
