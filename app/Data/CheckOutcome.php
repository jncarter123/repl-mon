<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\CheckStatus;

final readonly class CheckOutcome
{
    public function __construct(
        public CheckStatus $status,
        public string $message,
    ) {}
}
