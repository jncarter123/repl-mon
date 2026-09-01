<?php

declare(strict_types=1);

use App\Enums\CheckStatus;
use App\Models\AlertRecipient;
use App\Models\ReplicationAlert;
use App\Models\ServerPair;

beforeEach(function () {
    config()->set('replication.health.token', 'secret-token');
    config()->set('replication.health.stale_after_minutes', 5);
    config()->set('replication.health.delivery_failure_window_minutes', 60);

    // Somebody to email, so the "nobody would be told" warning stays out of the
    // way of tests that are about something else.
    AlertRecipient::factory()->create(['server_pair_id' => null, 'email' => 'ops@example.com']);
});

function health(array $query = [], array $headers = [])
{
    return test()->getJson('/api/health?'.http_build_query($query), array_merge([
        'X-Health-Token' => 'secret-token',
    ], $headers));
}

function healthText(array $query = [])
{
    return test()->get('/api/health?'.http_build_query($query), ['X-Health-Token' => 'secret-token']);
}

function checkedPair(CheckStatus $status, array $attributes = []): ServerPair
{
    return ServerPair::factory()->create(array_merge([
        'current_status' => $status,
        'last_checked_at' => now()->subSeconds(20),
        'last_lag_seconds' => 0.4,
        'last_message' => 'Beat arrived 0.4s behind.',
    ], $attributes));
}

it('answers 200 and OK when every pair is healthy', function () {
    checkedPair(CheckStatus::Ok, ['name' => 'orders']);

    $response = healthText();

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=utf-8');

    expect($response->getContent())
        ->toStartWith('REPLICATION OK - 1 healthy of 1 monitored pair')
        ->toContain('OK: orders is healthy, 0.4s behind')
        ->toContain('| total=1 enabled=1');
});

it('answers 503 and names the broken pair', function () {
    checkedPair(CheckStatus::Ok, ['name' => 'orders']);
    checkedPair(CheckStatus::Broken, ['name' => 'payments', 'last_message' => 'No beat has arrived.']);

    $response = healthText();

    $response->assertStatus(503);

    expect($response->getContent())
        ->toStartWith('REPLICATION CRITICAL - 1 broken, 1 healthy of 2 monitored pairs')
        ->toContain('CRITICAL: payments is broken — No beat has arrived.');
});

it('treats lag as a failure the status code can see', function () {
    checkedPair(CheckStatus::Lagging, ['name' => 'reporting', 'last_lag_seconds' => 94.0]);

    $response = healthText();

    $response->assertStatus(503);

    expect($response->getContent())
        ->toContain('REPLICATION WARNING')
        ->toContain('WARNING: reporting is lagging, 1m 34s behind');
});

it('is critical when nothing has checked the pairs lately, whatever the pairs last said', function () {
    checkedPair(CheckStatus::Ok, ['name' => 'orders', 'last_checked_at' => now()->subMinutes(20)]);

    $response = healthText();

    $response->assertStatus(503);

    expect($response->getContent())
        ->toContain('REPLICATION CRITICAL')
        ->toContain('MONITOR: 1 of 1 monitored pairs have not been checked for up to 20m')
        ->toContain('so nothing has checked it since');
});

it('warns rather than going quiet when there is nothing to watch', function () {
    healthText()->assertStatus(503)
        ->assertSee('REPLICATION WARNING - no pairs are configured');

    ServerPair::factory()->disabled()->create(['name' => 'orders']);

    $response = healthText();

    $response->assertStatus(503);

    expect($response->getContent())
        ->toContain('REPLICATION WARNING - nothing is being monitored: every pair is paused')
        ->toContain('MONITOR: orders is paused')
        ->toContain('PAUSED: orders is not being checked');
});

it('reports a pair nobody would be emailed about', function () {
    AlertRecipient::query()->delete();
    checkedPair(CheckStatus::Ok, ['name' => 'orders']);

    $response = healthText();

    $response->assertStatus(503);

    expect($response->getContent())
        ->toContain('REPLICATION WARNING')
        ->toContain('MONITOR: Nobody would be emailed about orders');
});

it('reports an alert that could not be delivered', function () {
    $pair = checkedPair(CheckStatus::Ok, ['name' => 'orders']);

    ReplicationAlert::factory()->create([
        'server_pair_id' => $pair->id,
        'delivery_error' => 'Connection to smtp.example.com refused',
        'sent_at' => now()->subMinutes(5),
    ]);

    $response = healthText();

    $response->assertStatus(503);

    expect($response->getContent())
        ->toContain('REPLICATION CRITICAL')
        ->toContain('MONITOR: 1 alert(s) could not be delivered')
        ->toContain('Connection to smtp.example.com refused');
});

it('forgets a delivery failure once it is outside the window', function () {
    $pair = checkedPair(CheckStatus::Ok, ['name' => 'orders']);

    ReplicationAlert::factory()->create([
        'server_pair_id' => $pair->id,
        'delivery_error' => 'Connection refused',
        'sent_at' => now()->subHours(6),
    ]);

    healthText()->assertOk();
});

it('narrows to one pair by name, key, or id', function () {
    checkedPair(CheckStatus::Broken, ['name' => 'payments']);
    $orders = checkedPair(CheckStatus::Ok, ['name' => 'orders']);

    foreach (['orders', $orders->monitor_key, (string) $orders->id] as $key) {
        $response = healthText(['pair' => $key]);

        $response->assertOk();

        expect($response->getContent())
            ->toContain('REPLICATION OK - 1 healthy of 1 monitored pair')
            ->not->toContain('payments');
    }
});

it('is critical about a check pointed at a pair that does not exist', function () {
    checkedPair(CheckStatus::Ok, ['name' => 'orders']);

    $response = healthText(['pair' => 'renamed-last-week']);

    $response->assertNotFound();

    expect($response->getContent())->toContain('REPLICATION CRITICAL - no pair called `renamed-last-week`');
});

it('serves json for anything that would rather have the numbers', function () {
    checkedPair(CheckStatus::Broken, ['name' => 'payments', 'last_lag_seconds' => null]);

    health()->assertStatus(503)
        ->assertJsonPath('status', 'critical')
        ->assertJsonPath('counts.broken', 1)
        ->assertJsonPath('pairs.0.name', 'payments')
        ->assertJsonPath('pairs.0.status', 'broken')
        ->assertJsonPath('pairs.0.stale', false);

    healthText(['format' => 'json'])->assertJsonPath('status', 'critical');
});

it('takes the token as a bearer, a header, or a query parameter', function () {
    checkedPair(CheckStatus::Ok);

    $this->get('/api/health', ['Authorization' => 'Bearer secret-token'])->assertOk();
    $this->get('/api/health', ['X-Health-Token' => 'secret-token'])->assertOk();
    $this->get('/api/health?token=secret-token')->assertOk();
});

it('refuses a wrong token and does not exist at all without one configured', function () {
    checkedPair(CheckStatus::Ok);

    $this->get('/api/health')->assertUnauthorized();
    $this->get('/api/health?token=wrong')->assertUnauthorized();

    config()->set('replication.health.token', null);

    $this->get('/api/health?token=secret-token')->assertNotFound();
});

it('needs no session, and never redirects a monitoring system to a login page', function () {
    checkedPair(CheckStatus::Ok);

    $this->get('/api/health?token=secret-token')->assertOk();
});
