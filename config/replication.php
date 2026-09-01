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
    | History retention
    |--------------------------------------------------------------------------
    |
    | One check per pair per minute is ~43k rows a month, so the history is
    | pruned. Alerts are kept far longer — they are the record of what you were
    | told and when.
    |
    */

    'retain_checks_days' => (int) env('REPL_RETAIN_CHECKS_DAYS', 14),

    'retain_alerts_days' => (int) env('REPL_RETAIN_ALERTS_DAYS', 365),

];
