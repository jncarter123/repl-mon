<?php

declare(strict_types=1);

namespace App\Data;

/**
 * The result of provisioning one pair: an ordered list of steps, and nothing
 * else. Like ProbeResult this carries facts only — what to show, what colour to
 * paint it and whether to offer a retry are the caller's business.
 */
final readonly class ProvisionReport
{
    /**
     * @param  list<ProvisionStep>  $steps
     */
    public function __construct(public array $steps = []) {}

    public function with(ProvisionStep $step): self
    {
        return new self([...$this->steps, $step]);
    }

    public function isSuccess(): bool
    {
        foreach ($this->steps as $step) {
            if (! $step->isSuccess()) {
                return false;
            }
        }

        return true;
    }

    /**
     * The first step that did not work — the one worth putting in a toast.
     */
    public function firstFailure(): ?ProvisionStep
    {
        foreach ($this->steps as $step) {
            if (! $step->isSuccess()) {
                return $step;
            }
        }

        return null;
    }

    /**
     * @return list<ProvisionStep>
     */
    public function failures(): array
    {
        return array_values(array_filter($this->steps, fn (ProvisionStep $step): bool => ! $step->isSuccess()));
    }
}
