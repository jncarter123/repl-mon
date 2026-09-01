<?php

declare(strict_types=1);

use App\Enums\Endpoint;
use App\Models\ServerPair;
use App\Support\GrantAdvice;

it('writes SQL that creates the schema and grants what the primary side needs', function () {
    $pair = ServerPair::factory()->make([
        'primary_database' => 'repl_monitor',
        'primary_username' => 'monitor_user',
    ]);

    expect(GrantAdvice::forSchema($pair, Endpoint::Primary))
        ->toContain('CREATE DATABASE IF NOT EXISTS `repl_monitor`')
        ->toContain('GRANT SELECT, INSERT, UPDATE, CREATE ON `repl_monitor`.* ')
        ->toContain("'monitor_user'@");
});

it('adds the status grant on the replica side, so a DBA is only asked once', function () {
    $pair = ServerPair::factory()->make([
        'replica_database' => 'repl_monitor',
        'replica_username' => 'monitor_user',
    ]);

    $sql = GrantAdvice::forSchema($pair, Endpoint::Replica);

    expect($sql)
        ->toContain('GRANT SELECT ON `repl_monitor`.*')
        ->toContain('REPLICA MONITOR')
        ->toContain('REPLICATION CLIENT')
        // The replica writes nothing; it must never be told to grant INSERT.
        ->not->toContain('INSERT');
});

it('never puts a password in advice that is meant to be pasted into a ticket', function () {
    $pair = ServerPair::factory()->make([
        'primary_password' => 'hunter2-primary',
        'replica_password' => 'hunter2-replica',
    ]);

    foreach (Endpoint::cases() as $endpoint) {
        expect(GrantAdvice::forSchema($pair, $endpoint))
            ->not->toContain('hunter2-primary')
            ->not->toContain('hunter2-replica');
    }
});

it('cannot be broken out of a quoted identifier or a quoted literal', function () {
    $pair = ServerPair::factory()->make([
        'primary_database' => 'repl`_monitor',
        'primary_username' => "bob'; DROP TABLE users; --",
    ]);

    $sql = GrantAdvice::forSchema($pair, Endpoint::Primary);

    expect($sql)
        ->toContain('`repl_monitor`')
        ->toContain("bob\\'; DROP TABLE users; --")
        ->and(substr_count($sql, '`') % 2)->toBe(0);
});
