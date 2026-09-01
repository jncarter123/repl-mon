<?php

declare(strict_types=1);

namespace App\Support;

final class Duration
{
    /**
     * Short, readable lag. Sub-minute values keep a decimal, because the
     * difference between 0.4s and 8s of lag is the whole story.
     */
    public static function humanize(?float $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }

        if ($seconds < 10) {
            return rtrim(rtrim(number_format($seconds, 2), '0'), '.').'s';
        }

        if ($seconds < 60) {
            return round($seconds).'s';
        }

        if ($seconds < 3600) {
            $minutes = intdiv((int) $seconds, 60);
            $rest = (int) $seconds % 60;

            return $rest === 0 ? "{$minutes}m" : "{$minutes}m {$rest}s";
        }

        $hours = intdiv((int) $seconds, 3600);
        $minutes = intdiv((int) $seconds % 3600, 60);

        return $minutes === 0 ? "{$hours}h" : "{$hours}h {$minutes}m";
    }
}
