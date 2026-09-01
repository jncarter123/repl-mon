<?php

declare(strict_types=1);

namespace App\Enums;

enum Endpoint: string
{
    case Primary = 'primary';
    case Replica = 'replica';

    public function label(): string
    {
        return match ($this) {
            self::Primary => 'Primary',
            self::Replica => 'Replica',
        };
    }
}
