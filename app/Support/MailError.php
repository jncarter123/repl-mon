<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;
use Throwable;

/**
 * The mail transports put the whole failing conversation into the exception —
 * "Failed to authenticate on SMTP server with username ..." and, on some
 * transports, the credential itself. These messages are stored on the alert
 * row, shown on the dashboard and printed by `replication:test-mail`, so the
 * mailer's own password must never survive the trip either.
 *
 * The companion to DatabaseError, which does the same job for a monitored
 * pair's credentials.
 */
final class MailError
{
    public const MAX_LENGTH = 500;

    public static function describe(Throwable $e): string
    {
        $message = (string) preg_replace('/\s+/', ' ', trim($e->getMessage()));

        foreach (self::secrets() as $secret) {
            $message = str_replace($secret, '[redacted]', $message);
        }

        return Str::limit($message, self::MAX_LENGTH);
    }

    /**
     * Every secret the configured mailers hold: an SMTP password, an SES
     * secret key or session token, and the API keys of the transports Laravel
     * ships with. Longest first, so a value that contains another is redacted
     * whole rather than leaving a tail behind.
     *
     * @return list<string>
     */
    private static function secrets(): array
    {
        $values = [];

        /** @var array<string, mixed> $mailers */
        $mailers = (array) config('mail.mailers', []);

        foreach ($mailers as $mailer) {
            if (is_array($mailer)) {
                $values[] = $mailer['password'] ?? null;
                $values[] = $mailer['url'] ?? null;
            }
        }

        $values[] = config('services.ses.secret');
        $values[] = config('services.ses.token');
        $values[] = config('services.postmark.key');
        $values[] = config('services.resend.key');

        $values = array_values(array_unique(array_filter(
            $values,
            fn (mixed $value): bool => is_string($value) && $value !== '' && $value !== 'null',
        )));

        usort($values, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        /** @var list<string> $values */
        return $values;
    }
}
