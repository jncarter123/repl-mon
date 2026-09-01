<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AlertRecipient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AlertRecipient>
 */
class AlertRecipientFactory extends Factory
{
    protected $model = AlertRecipient::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'server_pair_id' => null,
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'enabled' => true,
        ];
    }
}
