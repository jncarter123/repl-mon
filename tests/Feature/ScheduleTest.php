<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

// The minute loop went silent for ten minutes in production and the container
// log could not tell a check that died on its first line from one that cleared
// every pair. Both halves of that were properties of how the event is
// registered, not of any code in app/, so they are asserted here.

function checkEvent(): Event
{
    $events = collect(app(Schedule::class)->events())
        ->filter(fn (Event $event) => str_contains($event->command ?? '', 'replication:check'));

    expect($events)->toHaveCount(1);

    return $events->first();
}

it('checks every minute without overlapping', function () {
    $event = checkEvent();

    expect($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(10);
});

it('does not run the check in the background', function () {
    // runInBackground() builds `(check > /dev/null 2>&1 ; schedule:finish "$?")
    // > /dev/null 2>&1 &`, which sends the log channel to /dev/null along with
    // everything else, and makes Event::ensureMutexIsReleasedOnSignal() return
    // early — so a container stopped mid-check strands the mutex for the rest
    // of its ten minutes. Both failures are invisible until an outage goes
    // unreported, which is why this is a test and not a comment.
    expect(checkEvent()->runInBackground)->toBeFalse();
});

it('captures the check output so a failure can be reported', function () {
    // onFailure(fn (Stringable $output) => ...) is what routes the exception
    // into the log, and it only has anything to say if the output is being
    // captured rather than discarded to the default /dev/null.
    $event = checkEvent();

    expect($event->output)->not->toBe($event->getDefaultOutput())
        ->and($event->output)->toEndWith('.log');
});

it('releases the mutex on a termination signal', function () {
    // pcntl is installed in the image for this; without it the entrypoint's
    // schedule:clear-cache is the only thing standing between a restart and
    // ten blind minutes.
    expect(extension_loaded('pcntl'))->toBeTrue()
        ->and(checkEvent()->releaseOnTerminationSignals)->toBeTrue();
});
