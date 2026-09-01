<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\HealthToken;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HealthToken>
 */
class HealthTokenFactory extends Factory
{
    protected $model = HealthToken::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->domainWord(),
            'token' => bin2hex(random_bytes(24)),
            'last_used_at' => null,
        ];
    }
}
