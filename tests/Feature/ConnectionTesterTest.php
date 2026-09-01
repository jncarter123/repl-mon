<?php

declare(strict_types=1);

use App\Enums\Endpoint;
use App\Models\ServerPair;
use App\Services\ConnectionTester;
use App\Services\HeartbeatManager;
use Illuminate\Database\QueryException;
use Tests\Support\SchemalessConnections;
use Tests\Support\StubServerConnection;

/**
 * The interesting case here is the one an operator meets first: a pair whose
 * heartbeat schema does not exist yet. The DSN names that schema, so the connect
 * fails before anything can explain itself, and a test that answers "could not
 * connect" sends people off to create the database by hand — when the setup step
 * on the same page would have created it for them.
 */
function testerFor(Throwable $error, ?StubServerConnection $server = null): array
{
    $connections = new SchemalessConnections($error, $server ?? new StubServerConnection);

    return [new ConnectionTester($connections, new HeartbeatManager($connections)), $connections];
}

function unknownDatabase(string $schema = 'repl_monitor'): QueryException
{
    $pdo = new PDOException("SQLSTATE[42000] [1049] Unknown database '{$schema}'");
    $pdo->errorInfo = ['42000', 1049, "Unknown database '{$schema}'"];

    return new QueryException('pair_draft_primary', 'select 1', [], $pdo);
}

function refused(): QueryException
{
    $pdo = new PDOException("SQLSTATE[HY000] [1045] Access denied for user 'repl_monitor'@'10.0.0.5'");
    $pdo->errorInfo = ['HY000', 1045, 'Access denied'];

    return new QueryException('pair_draft_primary', 'select 1', [], $pdo);
}

it('reports a database that does not exist yet as something setup fixes, not a dead connection', function () {
    $pair = ServerPair::factory()->make(['primary_database' => 'repl_monitor']);
    [$tester] = testerFor(unknownDatabase());

    $result = $tester->test($pair, Endpoint::Primary);

    expect($result['ok'])->toBeTrue()
        ->and($result['schema_present'])->toBeFalse()
        ->and($result['heartbeat_table'])->toBeFalse()
        ->and($result['version'])->toBe('11.4.2-MariaDB')
        ->and($result['message'])->toContain('`repl_monitor` is not there yet')
        ->and($result['message'])->toContain('setting up the heartbeat creates it');
});

it('still answers the replica status question with no schema to connect to', function () {
    $pair = ServerPair::factory()->make(['replica_database' => 'repl_monitor']);
    [$tester] = testerFor(unknownDatabase(), new StubServerConnection(status: [(object) ['Replica_IO_Running' => 'Yes']]));

    $result = $tester->test($pair, Endpoint::Replica);

    expect($result['ok'])->toBeTrue()
        ->and($result['schema_present'])->toBeFalse()
        ->and($result['status_readable'])->toBeTrue();
});

it('does not dress a refused login up as a missing database', function () {
    $pair = ServerPair::factory()->make();
    [$tester, $connections] = testerFor(refused());

    $result = $tester->test($pair, Endpoint::Primary);

    expect($result['ok'])->toBeFalse()
        ->and($result['message'])->toContain('Access denied')
        ->and($connections->serverConnections)->toBe(0);
});

it('keeps the pair password out of a failure it reports', function () {
    $pair = ServerPair::factory()->make(['primary_password' => 'hunter2']);
    [$tester] = testerFor(new RuntimeException('Connection failed using password hunter2'));

    expect($tester->test($pair, Endpoint::Primary)['message'])
        ->not->toContain('hunter2')
        ->toContain('[redacted]');
});
