<?php

declare(strict_types=1);

namespace App\Livewire\Pairs;

use App\Enums\Endpoint;
use App\Livewire\Forms\ServerPairForm;
use App\Models\ServerPair;
use App\Services\ConnectionTester;
use App\Services\HeartbeatManager;
use App\Services\PairConnectionFactory;
use App\Support\DatabaseError;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Throwable;

class Form extends Component
{
    public ServerPairForm $form;

    /** @var array<string, array<string, mixed>> */
    public array $testResults = [];

    public function mount(?ServerPair $pair = null): void
    {
        if ($pair?->exists) {
            $this->form->setPair($pair);

            return;
        }

        $this->form->mountDefaults();
    }

    public function title(): string
    {
        return $this->form->pair ? "Edit {$this->form->pair->name}" : 'Add a server pair';
    }

    public function test(string $endpoint, ConnectionTester $tester): void
    {
        $target = Endpoint::from($endpoint);

        // Validate only what the connection needs, so an incomplete alerting
        // section does not stop someone checking their credentials.
        $this->validate(array_intersect_key(
            $this->form->rules(),
            array_flip([
                "form.{$endpoint}_host", "form.{$endpoint}_port",
                "form.{$endpoint}_username", "form.{$endpoint}_database",
            ])
        ) ?: []);

        try {
            $this->testResults[$endpoint] = $tester->test($this->form->draftPair(), $target);
        } catch (Throwable $e) {
            $this->testResults[$endpoint] = [
                'ok' => false,
                'message' => DatabaseError::describe($e),
                'version' => null,
                'heartbeat_table' => false,
                'status_readable' => null,
                'status_message' => null,
            ];
        }
    }

    public function installHeartbeat(PairConnectionFactory $connections, HeartbeatManager $heartbeats): void
    {
        $pair = $this->form->draftPair();

        try {
            $connection = $connections->connection($pair, Endpoint::Primary);
            $heartbeats->install($connection, $pair);

            Flux::toast(
                variant: 'success',
                text: "Created {$heartbeats->tableFor($pair)} on the primary. Replication should carry it to the replica.",
            );
        } catch (Throwable $e) {
            Flux::toast(variant: 'danger', heading: 'Could not create the heartbeat table', text: DatabaseError::describe($e, $pair));
        } finally {
            $connections->forget($pair);
        }
    }

    public function save(): void
    {
        $pair = $this->form->save();

        Flux::toast(variant: 'success', text: "{$pair->name} saved.");

        $this->redirectRoute('pairs.show', $pair, navigate: true);
    }

    public function render(): View
    {
        return view('livewire.pairs.form');
    }
}
