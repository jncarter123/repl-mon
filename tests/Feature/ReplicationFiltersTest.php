<?php

declare(strict_types=1);

use App\Support\ReplicationFilters;

it('finds nothing wrong when no filters are set', function () {
    $filters = new ReplicationFilters(
        ['binlog_do_db' => '', 'binlog_ignore_db' => ''],
        ['replicate_do_db' => '', 'replicate_ignore_db' => ''],
    );

    expect($filters->excludes('repl_monitor'))->toBeNull();
});

it('catches a schema missing from the primary allow list', function () {
    $filters = new ReplicationFilters(['binlog_do_db' => 'orders,billing']);

    expect($filters->excludes('repl_monitor'))
        ->toContain('binlog_do_db')
        ->toContain('binary log');
});

it('accepts a schema that is on the primary allow list', function () {
    $filters = new ReplicationFilters(['binlog_do_db' => 'orders,repl_monitor,billing']);

    expect($filters->excludes('repl_monitor'))->toBeNull();
});

it('catches a schema on the primary ignore list', function () {
    $filters = new ReplicationFilters(['binlog_ignore_db' => 'mysql,repl_monitor']);

    expect($filters->excludes('repl_monitor'))->toContain('binlog_ignore_db');
});

it('catches a schema missing from the replica allow list', function () {
    $filters = new ReplicationFilters([], ['replicate_do_db' => 'orders']);

    expect($filters->excludes('repl_monitor'))
        ->toContain('replicate_do_db')
        ->toContain('discards');
});

it('catches a schema on the replica ignore list', function () {
    $filters = new ReplicationFilters([], ['replicate_ignore_db' => 'repl_monitor']);

    expect($filters->excludes('repl_monitor'))->toContain('replicate_ignore_db');
});

it('blames the primary first, because nothing else matters if it never reaches the binlog', function () {
    $filters = new ReplicationFilters(
        ['binlog_ignore_db' => 'repl_monitor'],
        ['replicate_ignore_db' => 'repl_monitor'],
    );

    expect($filters->excludes('repl_monitor'))
        ->toContain('binlog_ignore_db')
        ->not->toContain('replicate_ignore_db');
});

it('ignores whitespace around list entries', function () {
    $filters = new ReplicationFilters(['binlog_do_db' => ' orders , repl_monitor ']);

    expect($filters->excludes('repl_monitor'))->toBeNull();
});

it('matches regardless of case, so a filter that differs only in case is still reported', function () {
    $filters = new ReplicationFilters(['binlog_ignore_db' => 'REPL_MONITOR']);

    expect($filters->excludes('repl_monitor'))->toContain('binlog_ignore_db');
});

it('does not treat an empty allow list as excluding everything', function () {
    $filters = new ReplicationFilters(['binlog_do_db' => '   ']);

    expect($filters->excludes('anything'))->toBeNull();
});

it('reports wildcard table filters without pretending to evaluate them', function () {
    $filters = new ReplicationFilters([], [
        'replicate_ignore_db' => '',
        'replicate_wild_ignore_table' => 'repl\_monitor.%',
    ]);

    expect($filters->excludes('repl_monitor'))->toBeNull()
        ->and($filters->wildcards())->toBe(['replicate_wild_ignore_table' => 'repl\_monitor.%']);
});

it('knows when it read nothing at all', function () {
    expect((new ReplicationFilters)->wereRead())->toBeFalse()
        ->and((new ReplicationFilters(['binlog_do_db' => '']))->wereRead())->toBeTrue();
});
