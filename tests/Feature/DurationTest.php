<?php

declare(strict_types=1);

use App\Support\Duration;

it('formats lag the way an operator reads it', function (?float $seconds, string $expected) {
    expect(Duration::humanize($seconds))->toBe($expected);
})->with([
    [null, '—'],
    [0.0, '0s'],
    [0.4, '0.4s'],
    [1.25, '1.25s'],
    [9.5, '9.5s'],
    [42.4, '42s'],
    [90.0, '1m 30s'],
    [120.0, '2m'],
    [3600.0, '1h'],
    [5400.0, '1h 30m'],
]);
