<?php

declare(strict_types=1);

namespace App\Enums;

enum CheckStatus: string
{
    /** Never checked, or checking was disabled. */
    case Unknown = 'unknown';

    /** The beat came through inside the pair's threshold. */
    case Ok = 'ok';

    /** The beat arrived, but late. */
    case Lagging = 'lagging';

    /** Replication itself is down: a thread stopped, or the beat never landed. */
    case Broken = 'broken';

    /** We could not reach one of the two servers to find out. */
    case Unreachable = 'unreachable';

    public function label(): string
    {
        return match ($this) {
            self::Unknown => 'Not yet checked',
            self::Ok => 'Healthy',
            self::Lagging => 'Lagging',
            self::Broken => 'Broken',
            self::Unreachable => 'Unreachable',
        };
    }

    /**
     * Flux badge colour.
     */
    public function color(): string
    {
        return match ($this) {
            self::Unknown => 'zinc',
            self::Ok => 'green',
            self::Lagging => 'amber',
            self::Broken => 'red',
            self::Unreachable => 'red',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Unknown => 'question-mark-circle',
            self::Ok => 'check-circle',
            self::Lagging => 'clock',
            self::Broken => 'x-circle',
            self::Unreachable => 'signal-slash',
        };
    }

    /**
     * Anything that is not Ok and not Unknown is worth an email.
     */
    public function isProblem(): bool
    {
        return $this !== self::Ok && $this !== self::Unknown;
    }

    /**
     * Ordering for the dashboard: worst first.
     */
    public function severity(): int
    {
        return match ($this) {
            self::Broken => 4,
            self::Unreachable => 3,
            self::Lagging => 2,
            self::Unknown => 1,
            self::Ok => 0,
        };
    }
}
