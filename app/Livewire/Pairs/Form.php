<?php

declare(strict_types=1);

namespace App\Livewire\Pairs;

use App\Data\ProvisionStep;
use App\Enums\Endpoint;
use App\Livewire\Forms\ServerPairForm;
use App\Models\ServerPair;
use App\Services\ConnectionTester;
use App\Services\HeartbeatProvisioner;
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

    /**
     * The last provisioning run, flattened for the view. Objects do not survive
     * a Livewire round trip; these do.
     *
     * @var list<array<string, mixed>>
     */
    public array $provisionSteps = [];

    /** Create the schema and table on the replica too, for pairs that do not replicate DDL. */
    public bool $provisionReplica = false;

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
        $this->validate($this->rulesFor([
            "{$endpoint}_host", "{$endpoint}_port",
            "{$endpoint}_username", "{$endpoint}_database",
        ]));

        try {
            $this->testResults[$endpoint] = $tester->test($this->form->draftPair(), $target);
        } catch (Throwable $e) {
            $this->testResults[$endpoint] = [
                'ok' => false,
                'message' => DatabaseError::describe($e),
                'version' => null,
                'schema_present' => false,
                'heartbeat_table' => false,
                'status_readable' => null,
                'status_message' => null,
            ];
        }
    }

    /**
     * Create the heartbeat schema, create the table in it, and prove a beat gets
     * across. The last step is the one that matters: everything before it can
     * succeed on a pair whose schema is filtered out of replication.
     */
    public function provision(HeartbeatProvisioner $provisioner): void
    {
        $this->validate($this->rulesFor([
            'primary_host', 'primary_port', 'primary_username', 'primary_database',
            'replica_host', 'replica_port', 'replica_username', 'replica_database',
            'heartbeat_table',
        ]));

        $pair = $this->form->draftPair();
        $report = $provisioner->provision($pair, $this->provisionReplica);

        $this->provisionSteps = array_map(
            fn (ProvisionStep $step): array => [
                'label' => $step->label,
                'outcome' => $step->outcome->label(),
                'color' => $step->outcome->color(),
                'icon' => $step->outcome->icon(),
                'message' => $step->message,
                'remedy' => $step->remedy,
            ],
            $report->steps,
        );

        if ($report->isSuccess()) {
            Flux::toast(variant: 'success', text: "{$pair->name} is set up and replicating.");

            return;
        }

        $failure = $report->firstFailure();

        Flux::toast(
            variant: 'danger',
            heading: 'Setup did not finish',
            text: $failure === null ? 'See the steps below.' : $failure->message,
        );
    }

    public function save(): void
    {
        $pair = $this->form->save();

        Flux::toast(variant: 'success', text: "{$pair->name} saved.");

        $this->redirectRoute('pairs.show', $pair, navigate: true);
    }

    /**
     * A subset of the form's rules, keyed the way the component sees them.
     *
     * The form object states its rules unprefixed; the component's properties
     * live under `form.`. Intersecting the two sets without accounting for that
     * quietly yields an empty rule set, which validates nothing at all.
     *
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    protected function rulesFor(array $fields): array
    {
        $rules = $this->form->rules();
        $subset = [];

        foreach ($fields as $field) {
            if (isset($rules[$field])) {
                $subset["form.{$field}"] = $rules[$field];
            }
        }

        return $subset;
    }

    public function render(): View
    {
        return view('livewire.pairs.form');
    }
}
