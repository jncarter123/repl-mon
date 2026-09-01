<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AlertKind;
use App\Enums\CheckStatus;
use App\Models\ReplicationAlert;
use App\Models\ServerPair;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReplicationAlert>
 */
class ReplicationAlertFactory extends Factory
{
    protected $model = ReplicationAlert::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'server_pair_id' => ServerPair::factory(),
            'kind' => AlertKind::Problem,
            'status' => CheckStatus::Broken,
            'subject' => '[pair] Replication Broken',
            'summary' => 'Replication threads are not both running.',
            'recipients' => ['ops@example.com'],
            'sent_at' => now(),
        ];
    }
}
