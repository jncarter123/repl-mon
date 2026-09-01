<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\HealthTokenFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A secret that lets something outside read `GET /api/health`.
 *
 * There can be several on purpose: rotating means issuing a new one, moving the
 * checks over, and deleting the old one — with no window in between where the
 * monitor's own monitor is failing for a reason nobody has to investigate.
 *
 * @property int $id
 * @property string $name
 * @property string $token
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class HealthToken extends Model
{
    /** @use HasFactory<HealthTokenFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'last_used_at' => 'datetime',
        ];
    }
}
