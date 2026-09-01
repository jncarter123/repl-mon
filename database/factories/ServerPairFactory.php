<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CheckStatus;
use App\Models\ServerPair;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServerPair>
 */
class ServerPairFactory extends Factory
{
    protected $model = ServerPair::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->domainWord().'-cluster',
            'description' => null,
            'enabled' => true,

            'primary_host' => '10.0.0.'.fake()->numberBetween(2, 250),
            'primary_port' => 3306,
            'primary_username' => 'repl_monitor',
            'primary_password' => 'primary-secret',
            'primary_database' => 'repl_monitor',
            'primary_use_tls' => false,

            'replica_host' => '10.0.1.'.fake()->numberBetween(2, 250),
            'replica_port' => 3306,
            'replica_username' => 'repl_monitor',
            'replica_password' => 'replica-secret',
            'replica_database' => 'repl_monitor',
            'replica_use_tls' => false,

            'heartbeat_table' => 'repl_monitor_heartbeat',
            'lag_threshold_seconds' => 60,
            'check_replica_status' => true,
            'failures_before_alert' => 1,
            'realert_after_minutes' => 60,
            'connect_timeout_seconds' => 5,

            'current_status' => CheckStatus::Unknown,
            'consecutive_failures' => 0,
            'alerting' => false,
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn (): array => ['enabled' => false]);
    }

    public function alerting(CheckStatus $status = CheckStatus::Broken): static
    {
        return $this->state(fn (): array => [
            'current_status' => $status,
            'alerting' => true,
            'consecutive_failures' => 3,
            'failing_since' => now()->subMinutes(3),
            'last_alert_at' => now()->subMinutes(3),
        ]);
    }
}
