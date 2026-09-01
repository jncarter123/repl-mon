<?php

declare(strict_types=1);

use App\Enums\ProvisionOutcome;
use App\Models\ServerPair;
use App\Services\HeartbeatManager;
use App\Services\HeartbeatProvisioner;
use App\Services\ReplicaStatusReader;
use Tests\Support\FailingConnections;

/**
 * The suite has no MariaDB, so what is exercised here is everything the
 * provisioner decides on the way to and from the server: how a refusal is told
 * apart from a fault, what is skipped once something has gone wrong, and what
 * never appears in a message.
 *
 * @return array{HeartbeatProvisioner, FailingConnections}
 */
function provisionerFailingWith(Throwable $error): array
{
    $connections = new FailingConnections($error);

    return [
        new HeartbeatProvisioner($connections, new HeartbeatManager($connections), new ReplicaStatusReader),
        $connections,
    ];
}

function accessDenied(int $code = 1044): PDOException
{
    $error = new PDOException("SQLSTATE[42000] [{$code}] Access denied for user 'repl_monitor'@'10.0.0.5' to database 'repl_monitor'");
    $error->errorInfo = ['42000', $code, 'Access denied'];

    return $error;
}

it('refuses a schema name it would have to interpolate unsafely', function () {
    $pair = ServerPair::factory()->make(['primary_database' => 'repl monitor; DROP DATABASE mysql']);
    [$provisioner] = provisionerFailingWith(new RuntimeException('should never connect'));

    $report = $provisioner->provision($pair);

    expect($report->isSuccess())->toBeFalse()
        ->and($report->steps[0]->outcome)->toBe(ProvisionOutcome::Failed)
        ->and($report->steps[0]->message)->toContain('Unsafe SQL identifier');
});

it('skips the steps that no longer make sense once one has failed', function () {
    $pair = ServerPair::factory()->make();
    [$provisioner] = provisionerFailingWith(accessDenied());

    $report = $provisioner->provision($pair);

    expect($report->steps)->toHaveCount(3)
        ->and($report->steps[0]->outcome)->toBe(ProvisionOutcome::Denied)
        ->and($report->steps[1]->outcome)->toBe(ProvisionOutcome::Skipped)
        ->and($report->steps[2]->outcome)->toBe(ProvisionOutcome::Skipped)
        // The verification is the point of the run; say so rather than pass it.
        ->and($report->steps[2]->label)->toBe('Replication');
});

it('treats a refusal as a grant to go and ask for, not as a fault', function () {
    $pair = ServerPair::factory()->make(['primary_database' => 'repl_monitor']);
    [$provisioner] = provisionerFailingWith(accessDenied());

    $step = $provisioner->provision($pair)->steps[0];

    expect($step->outcome)->toBe(ProvisionOutcome::Denied)
        ->and($step->outcome->color())->toBe('amber')
        ->and($step->remedy)->toContain('CREATE DATABASE IF NOT EXISTS `repl_monitor`')
        ->and($step->remedy)->toContain('GRANT');
});

it('recognises a refusal that only says so in words', function () {
    $pair = ServerPair::factory()->make();
    [$provisioner] = provisionerFailingWith(new RuntimeException('Access denied for user'));

    expect($provisioner->provision($pair)->steps[0]->outcome)->toBe(ProvisionOutcome::Denied);
});

it('does not offer a grant for something a grant would not fix', function () {
    $pair = ServerPair::factory()->make();
    [$provisioner] = provisionerFailingWith(new RuntimeException('Connection refused'));

    $step = $provisioner->provision($pair)->steps[0];

    expect($step->outcome)->toBe(ProvisionOutcome::Failed)
        ->and($step->remedy)->toBeNull();
});

it('reports a pair it cannot even write a beat on', function () {
    $pair = ServerPair::factory()->make();
    [$provisioner] = provisionerFailingWith(new RuntimeException('Connection refused'));

    $report = $provisioner->verify($pair);

    expect($report->steps)->toHaveCount(1)
        ->and($report->steps[0]->outcome)->toBe(ProvisionOutcome::Failed)
        ->and($report->steps[0]->message)->toContain('Could not write a beat on the primary');
});

it('lets go of the connections even when every step failed', function () {
    $pair = ServerPair::factory()->make();
    [$provisioner, $connections] = provisionerFailingWith(accessDenied());

    $provisioner->provision($pair);
    $provisioner->verify($pair);

    expect($connections->forgotten)->toBe(2);
});

it('never lets a password reach a step message', function () {
    $pair = ServerPair::factory()->make(['primary_password' => 'hunter2-primary']);
    [$provisioner] = provisionerFailingWith(
        new RuntimeException("SQLSTATE[HY000] connect failed using password 'hunter2-primary'")
    );

    $report = $provisioner->provision($pair);

    foreach ($report->steps as $step) {
        expect($step->message)->not->toContain('hunter2-primary');
    }

    expect($report->steps[0]->message)->toContain('[redacted]');
});

it('creates on the replica as well only when asked', function () {
    $pair = ServerPair::factory()->make();
    [$provisioner] = provisionerFailingWith(new RuntimeException('Connection refused'));

    expect($provisioner->provision($pair, includeReplica: false)->steps)->toHaveCount(3)
        ->and($provisioner->provision($pair, includeReplica: true)->steps)->toHaveCount(5);
});
