<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Heartbeat
    |--------------------------------------------------------------------------
    |
    | The monitor writes one row per pair on the primary and reads it back off
    | the replica. Both timestamps come from this host's clock, so the two
    | database servers' clocks never enter the arithmetic.
    |
    | After writing a beat we give replication a moment to carry it across
    | before measuring, otherwise a perfectly healthy pair would look a full
    | interval behind on every single check.
    |
    */

    'heartbeat_table' => env('REPL_HEARTBEAT_TABLE', 'repl_monitor_heartbeat'),

    'settle_timeout_ms' => (int) env('REPL_SETTLE_TIMEOUT_MS', 2000),

    'settle_poll_ms' => (int) env('REPL_SETTLE_POLL_MS', 200),

    /*
    | Setting a pair up runs the same measurement once, by hand, and can afford
    | to be far more patient about it than a check that runs every minute. A
    | replica that needs eight seconds on a quiet afternoon is worth knowing
    | about; it is not worth failing the setup over.
    */

    'provision_verify_timeout_ms' => (int) env('REPL_PROVISION_VERIFY_TIMEOUT_MS', 10000),

    /*
    |--------------------------------------------------------------------------
    | Connections
    |--------------------------------------------------------------------------
    |
    | A hung server must not hold up the other pairs, so every connection gets
    | a connect timeout and (on MariaDB) a statement time limit.
    |
    */

    'connect_timeout' => (int) env('REPL_CONNECT_TIMEOUT', 5),

    'max_statement_time' => (int) env('REPL_MAX_STATEMENT_TIME', 5),

    /*
    | Path to a CA bundle used when a pair has TLS switched on. Without one the
    | connection is still encrypted but the server certificate is not verified.
    */

    'ssl_ca' => env('REPL_SSL_CA'),

    /*
    |--------------------------------------------------------------------------
    | Defaults for a new pair
    |--------------------------------------------------------------------------
    */

    'defaults' => [
        'lag_threshold_seconds' => (int) env('REPL_DEFAULT_LAG_THRESHOLD', 60),
        'failures_before_alert' => (int) env('REPL_DEFAULT_FAILURES_BEFORE_ALERT', 1),
        'realert_after_minutes' => (int) env('REPL_DEFAULT_REALERT_MINUTES', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Health endpoint
    |--------------------------------------------------------------------------
    |
    | GET /api/health, for Icinga or anything else that speaks HTTP. It answers
    | the question this app cannot answer about itself from the inside: is the
    | monitor still running, and is every pair it watches healthy?
    |
    | A token is required. This one is optional: tokens are normally generated
    | on the dashboard, which takes effect without a restart and can be rotated
    | without a gap. Set this to hold the secret in the environment instead —
    | it works alongside them. With neither, the route 404s, because the
    | endpoint names your pairs and their state.
    |
    */

    'health' => [

        'token' => env('REPL_HEALTH_TOKEN'),

        /*
        | How old the newest check for a pair may get before the endpoint calls
        | it critical. This is the "nothing silently failed" number: checks run
        | every minute, so anything past a few minutes means the scheduler is
        | dead, wedged, or was never started, and every pair on the dashboard is
        | showing a status from before that happened.
        */

        'stale_after_minutes' => (int) env('REPL_HEALTH_STALE_AFTER_MINUTES', 5),

        /*
        | An alert that could not be delivered is a failure of the monitor
        | itself, so it is reported here for a while after it happens rather
        | than only sitting in the history where nobody is looking.
        */

        'delivery_failure_window_minutes' => (int) env('REPL_HEALTH_DELIVERY_WINDOW_MINUTES', 60),

    ],

    /*
    |--------------------------------------------------------------------------
    | History retention
    |--------------------------------------------------------------------------
    |
    | One check per pair per minute is ~43k rows a month, so the history is
    | pruned. Alerts are kept far longer — they are the record of what you were
    | told and when.
    |
    */

    'retain_checks_days' => (int) env('REPL_RETAIN_CHECKS_DAYS', 14),

    /*
    | How far back an alert looks over the pair's own checks to describe the
    | episode it is about. One check a minute, so the default covers a day —
    | long enough for an outage that started before anyone was awake, and
    | bounded so a pair that has been down for a week does not read its whole
    | history to send one email. An episode longer than this is reported as
    | starting at the edge of the window; it is still going, and the alert says
    | so either way.
    */

    'incident_lookback_checks' => (int) env('REPL_INCIDENT_LOOKBACK_CHECKS', 1440),

    'retain_alerts_days' => (int) env('REPL_RETAIN_ALERTS_DAYS', 365),

];
