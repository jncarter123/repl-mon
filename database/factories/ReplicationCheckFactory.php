<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CheckStatus;
use App\Models\ReplicationCheck;
use App\Models\ServerPair;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReplicationCheck>
 */
class ReplicationCheckFactory extends Factory
{
    protected $model = ReplicationCheck::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'server_pair_id' => ServerPair::factory(),
            'status' => CheckStatus::Ok,
            'primary_reachable' => true,
            'replica_reachable' => true,
            'lag_seconds' => 0.4,
            'io_running' => 'Yes',
            'sql_running' => 'Yes',
            'seconds_behind_source' => 0,
            'message' => 'Replica is 0.4s behind, within the 60s threshold.',
            'duration_ms' => 120,
            'checked_at' => now(),
        ];
    }
}
