<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The three words a monitoring system understands. Nagios/Icinga plugins speak
 * OK / WARNING / CRITICAL, so the health endpoint does too — its own five-state
 * CheckStatus is a level of detail the check command has no way to act on.
 *
 * There is no UNKNOWN: if this app cannot answer, it does not answer at all and
 * the HTTP request fails, which the checker will see for itself.
 */
enum HealthLevel: string
{
    case Ok = 'ok';

    case Warning = 'warning';

    case Critical = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::Ok => 'OK',
            self::Warning => 'WARNING',
            self::Critical => 'CRITICAL',
        };
    }

    public function severity(): int
    {
        return match ($this) {
            self::Ok => 0,
            self::Warning => 1,
            self::Critical => 2,
        };
    }

    /**
     * Anything short of healthy answers 503, lag included. A check_http that
     * only looks at the status code has to see a lagging replica too, or the
     * one failure mode this endpoint exists to prevent — a problem nobody is
     * told about — comes straight back.
     */
    public function httpStatus(): int
    {
        return $this === self::Ok ? 200 : 503;
    }

    public function isProblem(): bool
    {
        return $this !== self::Ok;
    }

    public static function worst(HealthLevel ...$levels): self
    {
        $worst = self::Ok;

        foreach ($levels as $level) {
            if ($level->severity() > $worst->severity()) {
                $worst = $level;
            }
        }

        return $worst;
    }

    /**
     * How a pair's own status reads to a monitoring system. Never-checked is a
     * warning rather than an unknown: a pair that has been sitting at "not yet
     * checked" is either brand new or never getting checked, and both are worth
     * a look.
     */
    public static function forCheckStatus(CheckStatus $status): self
    {
        return match ($status) {
            CheckStatus::Ok => self::Ok,
            CheckStatus::Lagging, CheckStatus::Unknown => self::Warning,
            CheckStatus::Broken, CheckStatus::Unreachable => self::Critical,
        };
    }
}
