<?php

declare(strict_types=1);

namespace App\Enums;

enum AlertKind: string
{
    case Problem = 'problem';
    case Recovery = 'recovery';

    public function label(): string
    {
        return match ($this) {
            self::Problem => 'Problem',
            self::Recovery => 'Recovered',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Problem => 'red',
            self::Recovery => 'green',
        };
    }
}
