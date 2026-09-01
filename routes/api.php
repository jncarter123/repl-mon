<?php

use App\Http\Controllers\HealthController;
use App\Http\Middleware\RequireHealthToken;
use Illuminate\Support\Facades\Route;

/*
| One route, deliberately outside the web group: no session, no CSRF, no
| redirect to a login page. Something is meant to poll this every minute and
| act on the status code.
|
| `?pair=<name|key|id>` narrows it to a single pair, for an Icinga service
| attached to the host that pair lives on.
*/

Route::get('health', HealthController::class)
    ->middleware(RequireHealthToken::class)
    ->name('api.health');
