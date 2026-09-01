<?php

declare(strict_types=1);

use App\Enums\CheckStatus;
use App\Livewire\Pairs;
use App\Livewire\Recipients;
use App\Models\AlertRecipient;
use App\Models\ServerPair;
use App\Models\User;
use App\Services\PairConnectionFactory;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('renders every page', function (string $route) {
    $pair = ServerPair::factory()->create();

    $this->get(route($route, str_contains($route, '{') || in_array($route, ['pairs.show', 'pairs.edit'], true) ? $pair : []))
        ->assertOk();
})->with(['dashboard', 'pairs.index', 'pairs.create', 'pairs.show', 'pairs.edit', 'recipients.index']);

it('creates a pair and stores its passwords encrypted', function () {
    Livewire::test(Pairs\Form::class)
        ->set('form.name', 'orders → replica-1')
        ->set('form.primary_host', '10.0.0.10')
        ->set('form.primary_username', 'repl_monitor')
        ->set('form.primary_password', 'primary-secret')
        ->set('form.primary_database', 'repl_monitor')
        ->set('form.replica_host', '10.0.1.10')
        ->set('form.replica_username', 'repl_monitor')
        ->set('form.replica_password', 'replica-secret')
        ->set('form.replica_database', 'repl_monitor')
        ->call('save')
        ->assertHasNoErrors();

    $pair = ServerPair::query()->sole();

    expect($pair->primary_password)->toBe('primary-secret')
        ->and($pair->monitor_key)->not->toBeEmpty();

    // What is actually on disk must not be the password.
    $stored = DB::table('server_pairs')->where('id', $pair->id)->value('primary_password');

    expect($stored)->not->toBe('primary-secret')
        ->and($stored)->not->toContain('primary-secret');
});

it('keeps the stored password when the field is left blank on edit', function () {
    $pair = ServerPair::factory()->create(['primary_password' => 'original-secret']);

    Livewire::test(Pairs\Form::class, ['pair' => $pair])
        ->assertSet('form.primary_password', '')
        ->set('form.name', 'renamed')
        ->call('save')
        ->assertHasNoErrors();

    expect($pair->fresh()->primary_password)->toBe('original-secret')
        ->and($pair->fresh()->name)->toBe('renamed');
});

it('clears a password only when explicitly told to', function () {
    $pair = ServerPair::factory()->create(['primary_password' => 'original-secret']);

    Livewire::test(Pairs\Form::class, ['pair' => $pair])
        ->set('form.primary_no_password', true)
        ->call('save')
        ->assertHasNoErrors();

    expect($pair->fresh()->primary_password)->toBe('');
});

it('refuses a heartbeat table name that is not a plain identifier', function () {
    Livewire::test(Pairs\Form::class)
        ->set('form.name', 'injection')
        ->set('form.primary_host', 'a')->set('form.primary_username', 'a')->set('form.primary_database', 'a')
        ->set('form.replica_host', 'b')->set('form.replica_username', 'b')->set('form.replica_database', 'b')
        ->set('form.heartbeat_table', 'hb`; DROP TABLE users; --')
        ->call('save')
        ->assertHasErrors(['form.heartbeat_table']);

    expect(ServerPair::query()->count())->toBe(0);
});

it('refuses an unsafe identifier at the connection layer too', function () {
    // The form is the first lock; this is the one that matters if a row is
    // ever written by anything but the form.
    expect(fn () => app(PairConnectionFactory::class)->assertSafeIdentifier('hb; DROP TABLE users'))
        ->toThrow(InvalidArgumentException::class);
});

it('stops checking a paused pair without leaving it mid-alert', function () {
    $pair = ServerPair::factory()->alerting()->create();

    Livewire::test(Pairs\Index::class)->call('toggle', $pair->id);

    $pair->refresh();

    expect($pair->enabled)->toBeFalse()
        ->and($pair->alerting)->toBeFalse()
        ->and($pair->consecutive_failures)->toBe(0);
});

it('adds a pair recipient, which takes the pair off the global list', function () {
    AlertRecipient::factory()->create(['server_pair_id' => null, 'email' => 'ops@example.com']);
    $pair = ServerPair::factory()->create();

    Livewire::test(Pairs\Show::class, ['pair' => $pair])
        ->assertSet('recipientEmail', '')
        ->set('recipientEmail', 'orders@example.com')
        ->call('addRecipient')
        ->assertHasNoErrors();

    expect($pair->fresh()->resolvedRecipients()->pluck('email')->all())->toBe(['orders@example.com']);
});

it('rejects a duplicate email on the global list', function () {
    AlertRecipient::factory()->create(['server_pair_id' => null, 'email' => 'ops@example.com']);

    Livewire::test(Recipients\Index::class)
        ->set('email', 'ops@example.com')
        ->call('add')
        ->assertHasErrors(['email']);

    expect(AlertRecipient::query()->count())->toBe(1);
});

it('deletes a pair with its history', function () {
    $pair = ServerPair::factory()->create();
    $pair->checks()->create([
        'status' => CheckStatus::Ok,
        'checked_at' => now(),
    ]);

    Livewire::test(Pairs\Index::class)->call('delete', $pair->id);

    expect(ServerPair::query()->count())->toBe(0)
        ->and(DB::table('replication_checks')->count())->toBe(0);
});

it('renders the empty states', function () {
    // The pages above always had a pair, so the "nothing here yet" branches
    // were never actually rendered.
    ServerPair::query()->delete();

    $this->get(route('dashboard'))->assertOk()->assertSee('No pairs configured yet');
    $this->get(route('pairs.index'))->assertOk()->assertSee('Nothing here yet');
    $this->get(route('recipients.index'))->assertOk()->assertSee('No active global recipients');
});

it('warns on a pair that would email nobody', function () {
    $pair = ServerPair::factory()->create();

    $this->get(route('pairs.show', $pair))
        ->assertOk()
        ->assertSee('Nobody would be emailed');
});
