<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AlertRecipientFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $server_pair_id
 * @property string|null $name
 * @property string $email
 * @property bool $enabled
 * @property-read ServerPair|null $serverPair
 */
class AlertRecipient extends Model
{
    /** @use HasFactory<AlertRecipientFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<ServerPair, $this>
     */
    public function serverPair(): BelongsTo
    {
        return $this->belongsTo(ServerPair::class);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeGlobal(Builder $query): void
    {
        $query->whereNull('server_pair_id');
    }

    public function isGlobal(): bool
    {
        return $this->server_pair_id === null;
    }
}
