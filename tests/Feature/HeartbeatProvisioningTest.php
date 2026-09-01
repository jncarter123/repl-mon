<?php

declare(strict_types=1);

use App\Data\ProvisionReport;
use App\Data\ProvisionStep;
use App\Enums\ProvisionOutcome;
use App\Livewire\Pairs;
use App\Models\ServerPair;
use App\Models\User;
use App\Services\HeartbeatProvisioner;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\FakeProvisioner;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

function fakeProvisioner(ProvisionStep ...$steps): FakeProvisioner
{
    $fake = new FakeProvisioner(new ProvisionReport($steps));

    app()->instance(HeartbeatProvisioner::class, $fake);

    return $fake;
}

function filledForm(): Testable
{
    return Livewire::test(Pairs\Form::class)
        ->set('form.name', 'orders → replica-1')
        ->set('form.primary_host', '10.0.0.10')
        ->set('form.primary_username', 'repl_monitor')
        ->set('form.primary_database', 'repl_monitor')
        ->set('form.replica_host', '10.0.1.10')
        ->set('form.replica_username', 'repl_monitor')
        ->set('form.replica_database', 'repl_monitor');
}

it('shows every step of a successful setup', function () {
    fakeProvisioner(
        new ProvisionStep('Heartbeat schema · Primary', ProvisionOutcome::Created, 'Created `repl_monitor`.'),
        new ProvisionStep('Heartbeat table · Primary', ProvisionOutcome::Created, 'Created `repl_monitor_heartbeat`.'),
        new ProvisionStep('Replication', ProvisionOutcome::Verified, 'The beat reached the replica in 0.3s.'),
    );

    $component = filledForm()->call('provision')->assertHasNoErrors();

    expect($component->get('provisionSteps'))->toHaveCount(3)
        ->and($component->get('provisionSteps')[2]['outcome'])->toBe('Replicating')
        ->and($component->get('provisionSteps')[2]['color'])->toBe('green');
});

it('carries the grant advice through to the page', function () {
    fakeProvisioner(new ProvisionStep(
        'Heartbeat schema · Primary',
        ProvisionOutcome::Denied,
        'Could not create it.',
        'GRANT SELECT, INSERT, UPDATE, CREATE ON `repl_monitor`.* TO ...',
    ));

    $steps = filledForm()->call('provision')->get('provisionSteps');

    expect($steps[0]['remedy'])->toContain('GRANT SELECT, INSERT, UPDATE, CREATE')
        ->and($steps[0]['color'])->toBe('amber');
});

it('only touches the replica when the operator asks for it', function () {
    $fake = fakeProvisioner(new ProvisionStep('Replication', ProvisionOutcome::Verified, 'Fine.'));

    filledForm()->call('provision');
    expect($fake->sawIncludeReplica)->toBeFalse();

    filledForm()->set('provisionReplica', true)->call('provision');
    expect($fake->sawIncludeReplica)->toBeTrue();
});

it('will not try to provision a pair whose connection details are incomplete', function () {
    fakeProvisioner(new ProvisionStep('Replication', ProvisionOutcome::Verified, 'Fine.'));

    Livewire::test(Pairs\Form::class)
        ->set('form.primary_host', '')
        ->call('provision')
        ->assertHasErrors(['form.primary_host', 'form.replica_host']);
});

it('provisions what is on screen rather than what was last saved', function () {
    $pair = ServerPair::factory()->create(['primary_host' => '10.0.0.10']);
    $fake = fakeProvisioner(new ProvisionStep('Replication', ProvisionOutcome::Verified, 'Fine.'));

    Livewire::test(Pairs\Form::class, ['pair' => $pair])
        ->set('form.primary_host', '10.9.9.9')
        ->call('provision');

    expect($fake->sawPair?->primary_host)->toBe('10.9.9.9');
});

it('verifies replication from the pair page and keeps the explanation on screen', function () {
    fakeProvisioner(new ProvisionStep(
        'Replication',
        ProvisionOutcome::NotArrived,
        "The primary's `binlog_ignore_db` lists `repl_monitor`.",
    ));

    $pair = ServerPair::factory()->create();

    Livewire::test(Pairs\Show::class, ['pair' => $pair])
        ->call('verifyReplication')
        ->assertSet('verifyResult.outcome', 'Did not arrive')
        ->assertSet('verifyResult.color', 'red')
        ->assertSee('binlog_ignore_db');
});

it('shows nothing on the pair page until it has been asked', function () {
    Livewire::test(Pairs\Show::class, ['pair' => ServerPair::factory()->create()])
        ->assertSet('verifyResult', null);
});

it('renders every outcome the panel can be handed', function () {
    // Each outcome carries its own Flux colour and icon; a bad one only shows up
    // on the failure path, which is exactly where nobody wants a broken page.
    fakeProvisioner(...array_map(
        fn (ProvisionOutcome $outcome): ProvisionStep => new ProvisionStep(
            "Step {$outcome->value}",
            $outcome,
            "Message for {$outcome->value}.",
            $outcome === ProvisionOutcome::Denied ? 'GRANT ALL THE THINGS;' : null,
        ),
        ProvisionOutcome::cases(),
    ));

    $component = filledForm()->call('provision');

    expect($component->get('provisionSteps'))->toHaveCount(count(ProvisionOutcome::cases()));

    foreach (ProvisionOutcome::cases() as $outcome) {
        $component->assertSee("Message for {$outcome->value}.");
    }
});
