<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Stringable;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| The whole monitor hangs off this one line. withoutOverlapping keeps a run
| that is waiting on a dead server from stacking on top of the next minute's;
| onFailure is the last line of defence for the monitor watching the monitor.
|
| Deliberately NOT runInBackground(), and both reasons are the same outage:
|
|   The background form is `(check > /dev/null 2>&1 ; schedule:finish "$?")
|   > /dev/null 2>&1 &`. Every descriptor in there is /dev/null, including the
|   one LOG_CHANNEL=stderr writes to, so a check that died on its first line
|   and a check that cleared every pair produced identical container logs — and
|   the Log::warning for "nobody was emailed" went nowhere too. In the
|   foreground the after-callbacks run inside schedule:run, whose stderr is the
|   pipe schedule:work copies to its own output, so the Log::error below
|   actually reaches `docker logs`.
|
|   The background form also opts out of the mutex's own safety net:
|   Event::ensureMutexIsReleasedOnSignal() returns early on `$this->
|   runInBackground`, and the lock is instead released by a separate
|   `schedule:finish` process. Stop the container mid-check and that second
|   process never runs, so the lock is stranded until its TTL expires and the
|   monitor is blind for the remainder of the ten minutes. In the foreground
|   pcntl catches the SIGTERM and drops the lock on the way out.
|
| The cost is that schedule:run now waits for the check instead of returning in
| 9ms. That is fine here: schedule:work starts an independent schedule:run every
| minute regardless, and withoutOverlapping is what stops those stacking.
*/
Schedule::command('replication:check')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->onFailure(function (Stringable $output) {
        Log::error('replication:check failed', [
            'output' => trim((string) $output),
        ]);
    });

/*
| Same reporting for the same reason. This one runs once a day, so a silent
| failure is a store that grows without bound and nothing saying why until the
| volume fills.
*/
Schedule::command('replication:prune')
    ->dailyAt('03:10')
    ->onFailure(function (Stringable $output) {
        Log::error('replication:prune failed', [
            'output' => trim((string) $output),
        ]);
    });
