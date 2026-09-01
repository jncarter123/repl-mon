<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| The whole monitor hangs off this one line. withoutOverlapping keeps a run
| that is waiting on a dead server from stacking on top of the next minute's;
| onFailure is the last line of defence for the monitor watching the monitor.
*/
Schedule::command('replication:check')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->runInBackground();

Schedule::command('replication:prune')
    ->dailyAt('03:10');
