<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\ProvisionOutcome;

/**
 * One step of setting a pair up, reported on its own. The steps are kept
 * separate for the same reason ConnectionTester reports each grant separately:
 * a half-finished setup should say which half.
 */
final readonly class ProvisionStep
{
    /**
     * @param  string  $label  What the operator sees, e.g. "Heartbeat table · Primary".
     * @param  ?string  $remedy  SQL to hand somebody with the grant. Never contains
     *                           a password — see GrantAdvice.
     */
    public function __construct(
        public string $label,
        public ProvisionOutcome $outcome,
        public string $message,
        public ?string $remedy = null,
    ) {}

    public function isSuccess(): bool
    {
        return $this->outcome->isSuccess();
    }
}
