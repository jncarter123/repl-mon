<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How one provisioning step turned out. "We did not need to" and "we were not
 * allowed to" are kept apart from "it broke": the first is success, the second
 * is a grant to go and ask for, and only the third is a fault.
 */
enum ProvisionOutcome: string
{
    /** We made it. */
    case Created = 'created';

    /** It was already there, which is just as good. */
    case AlreadyPresent = 'already_present';

    /** The step was not asked for — e.g. the replica side, without --replica. */
    case Skipped = 'skipped';

    /** The beat crossed to the replica. */
    case Verified = 'verified';

    /** The server refused us. Not a fault: somebody needs to run a GRANT. */
    case Denied = 'denied';

    /** We were allowed to look, and the beat did not arrive. */
    case NotArrived = 'not_arrived';

    /** Something else went wrong. */
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Created',
            self::AlreadyPresent => 'Already there',
            self::Skipped => 'Skipped',
            self::Verified => 'Replicating',
            self::Denied => 'Not permitted',
            self::NotArrived => 'Did not arrive',
            self::Failed => 'Failed',
        };
    }

    /**
     * Flux badge colour. A denial is amber, not red — the monitor is not broken,
     * it is waiting on somebody with the grant.
     */
    public function color(): string
    {
        return match ($this) {
            self::Created, self::AlreadyPresent, self::Verified => 'green',
            self::Skipped => 'zinc',
            self::Denied => 'amber',
            self::NotArrived, self::Failed => 'red',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Created, self::AlreadyPresent, self::Verified => 'check-circle',
            self::Skipped => 'minus-circle',
            self::Denied => 'lock-closed',
            self::NotArrived, self::Failed => 'x-circle',
        };
    }

    /**
     * Did the step leave the pair in the state we wanted?
     */
    public function isSuccess(): bool
    {
        return match ($this) {
            self::Created, self::AlreadyPresent, self::Skipped, self::Verified => true,
            self::Denied, self::NotArrived, self::Failed => false,
        };
    }
}
