<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ServerPair;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Throwable;

/**
 * PDO puts the whole failing statement and its bindings into the exception
 * message. These messages end up in the checks table, on screen and in alert
 * emails, so a credential must never survive the trip.
 */
final class DatabaseError
{
    public const MAX_LENGTH = 500;

    public static function describe(Throwable $e, ?ServerPair $pair = null): string
    {
        $message = $e instanceof QueryException
            // The bindings carry nothing useful here and everything risky.
            ? (string) ($e->getPrevious()?->getMessage() ?? $e->getMessage())
            : $e->getMessage();

        $message = (string) preg_replace('/\s+/', ' ', trim($message));

        foreach (self::secrets($pair) as $secret) {
            $message = str_replace($secret, '[redacted]', $message);
        }

        return Str::limit($message, self::MAX_LENGTH);
    }

    /**
     * @return list<string>
     */
    private static function secrets(?ServerPair $pair): array
    {
        if ($pair === null) {
            return [];
        }

        return array_values(array_filter([
            $pair->primary_password,
            $pair->replica_password,
        ], fn (?string $value): bool => is_string($value) && $value !== ''));
    }
}
