<?php

declare(strict_types=1);

use App\Enums\AlertKind;
use App\Enums\CheckStatus;
use App\Livewire\Dashboard;
use App\Livewire\Pairs\Show;
use App\Models\ReplicationAlert;
use App\Models\ServerPair;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

/**
 * The overnight case this exists for: a recovery alert, read the next morning
 * by somebody who slept through the problem.
 */
function overnightRecovery(ServerPair $pair): ReplicationAlert
{
    return ReplicationAlert::factory()->create([
        'server_pair_id' => $pair->getKey(),
        'kind' => AlertKind::Recovery,
        'status' => CheckStatus::Ok,
        'subject' => "[{$pair->name}] Replication recovered — Broken for 22m",
        'summary' => 'Replica is 0.3s behind, within the 60s threshold.',
        'incident_started_at' => now()->subHours(8),
        'incident_duration_seconds' => 22 * 60,
        'failed_checks' => 22,
        'worst_status' => CheckStatus::Broken,
        'peak_lag_seconds' => 1240.5,
        'first_failure_message' => 'Replication threads are not both running (IO: Connecting, SQL: Yes).',
        'replica_error' => 'Error 2013: Lost connection to server during query',
        'status_counts' => ['broken' => 20, 'lagging' => 2],
    ]);
}

it('says what the outage was on the dashboard, not just that mail was sent', function () {
    $pair = ServerPair::factory()->create();
    overnightRecovery($pair);

    Livewire::test(Dashboard::class)
        ->assertSee('Broken for 22m — 22 failed checks')
        ->assertSee('Replication threads are not both running (IO: Connecting, SQL: Yes).')
        ->assertSee('Error 2013: Lost connection to server during query')
        ->assertSee('Broken ×20, Lagging ×2')
        ->assertSee('Worst lag measured');
});

it('shows the same detail on the pair it happened to', function () {
    $pair = ServerPair::factory()->create();
    overnightRecovery($pair);

    Livewire::test(Show::class, ['pair' => $pair])
        ->assertSee('Broken for 22m — 22 failed checks')
        ->assertSee('Error 2013: Lost connection to server during query');
});

it('says plainly when an older alert has no episode recorded', function () {
    $pair = ServerPair::factory()->create();

    ReplicationAlert::factory()->create([
        'server_pair_id' => $pair->getKey(),
        'subject' => "[{$pair->name}] Replication Broken",
        'summary' => 'Replication threads are not both running.',
    ]);

    Livewire::test(Dashboard::class)
        ->assertSee('No incident detail was recorded')
        ->assertSee("[{$pair->name}] Replication Broken");
});
