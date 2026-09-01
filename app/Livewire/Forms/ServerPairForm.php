<?php

declare(strict_types=1);

namespace App\Livewire\Forms;

use App\Models\ServerPair;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Form;

class ServerPairForm extends Form
{
    public ?ServerPair $pair = null;

    public string $name = '';

    public string $description = '';

    public bool $enabled = true;

    public string $primary_host = '';

    public int $primary_port = 3306;

    public string $primary_username = '';

    public string $primary_password = '';

    public string $primary_database = '';

    public bool $primary_use_tls = false;

    public bool $primary_no_password = false;

    public string $replica_host = '';

    public int $replica_port = 3306;

    public string $replica_username = '';

    public string $replica_password = '';

    public string $replica_database = '';

    public bool $replica_use_tls = false;

    public bool $replica_no_password = false;

    public string $heartbeat_table = 'repl_monitor_heartbeat';

    public int $lag_threshold_seconds = 60;

    public bool $check_replica_status = true;

    public int $failures_before_alert = 1;

    public int $realert_after_minutes = 60;

    public int $connect_timeout_seconds = 5;

    public function mountDefaults(): void
    {
        $this->heartbeat_table = (string) config('replication.heartbeat_table');
        $this->lag_threshold_seconds = (int) config('replication.defaults.lag_threshold_seconds');
        $this->failures_before_alert = (int) config('replication.defaults.failures_before_alert');
        $this->realert_after_minutes = (int) config('replication.defaults.realert_after_minutes');
        $this->connect_timeout_seconds = (int) config('replication.connect_timeout');
    }

    public function setPair(ServerPair $pair): void
    {
        $this->pair = $pair;

        foreach ([
            'name', 'description', 'enabled',
            'primary_host', 'primary_port', 'primary_username', 'primary_database', 'primary_use_tls',
            'replica_host', 'replica_port', 'replica_username', 'replica_database', 'replica_use_tls',
            'heartbeat_table', 'lag_threshold_seconds', 'check_replica_status',
            'failures_before_alert', 'realert_after_minutes', 'connect_timeout_seconds',
        ] as $field) {
            $this->{$field} = $pair->{$field} ?? $this->{$field};
        }

        // Stored passwords are never sent back to the browser. Blank means
        // "leave it as it is"; the explicit "no password" switch is the only
        // way to clear one, so a blank field can never silently wipe a
        // credential the form was not shown.
        $this->primary_password = '';
        $this->replica_password = '';
        $this->primary_no_password = ($pair->primary_password ?? '') === '';
        $this->replica_no_password = ($pair->replica_password ?? '') === '';
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:191', Rule::unique('server_pairs', 'name')->ignore($this->pair?->getKey())],
            'description' => ['nullable', 'string', 'max:2000'],
            'enabled' => ['boolean'],

            'primary_host' => ['required', 'string', 'max:191'],
            'primary_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'primary_username' => ['required', 'string', 'max:191'],
            'primary_password' => ['nullable', 'string', 'max:191'],
            'primary_database' => ['required', 'string', 'max:64'],
            'primary_use_tls' => ['boolean'],

            'replica_host' => ['required', 'string', 'max:191'],
            'replica_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'replica_username' => ['required', 'string', 'max:191'],
            'replica_password' => ['nullable', 'string', 'max:191'],
            'replica_database' => ['required', 'string', 'max:64'],
            'replica_use_tls' => ['boolean'],

            // Goes straight into the heartbeat SQL as an identifier, where no
            // placeholder is possible. PairConnectionFactory refuses anything
            // else as well; this is the half the user gets to see.
            'heartbeat_table' => ['required', 'string', 'regex:/^[A-Za-z0-9_]{1,64}$/'],
            'lag_threshold_seconds' => ['required', 'integer', 'min:1', 'max:86400'],
            'check_replica_status' => ['boolean'],
            'failures_before_alert' => ['required', 'integer', 'min:1', 'max:60'],
            'realert_after_minutes' => ['required', 'integer', 'min:0', 'max:10080'],
            'connect_timeout_seconds' => ['required', 'integer', 'min:1', 'max:60'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function validationAttributes(): array
    {
        return [
            'heartbeat_table' => 'heartbeat table',
            'lag_threshold_seconds' => 'lag threshold',
            'failures_before_alert' => 'failures before alerting',
            'realert_after_minutes' => 'reminder interval',
            'connect_timeout_seconds' => 'connect timeout',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'heartbeat_table.regex' => 'The heartbeat table may only contain letters, numbers and underscores.',
        ];
    }

    public function save(): ServerPair
    {
        $this->validate();

        $pair = $this->pair ?? new ServerPair;
        $pair->fill($this->attributesForModel());
        $pair->save();

        $this->pair = $pair;

        return $pair;
    }

    /**
     * Build an unsaved pair carrying whatever is on screen right now, so the
     * "Test connection" button tests what the operator is looking at rather
     * than what was last saved.
     */
    public function draftPair(): ServerPair
    {
        $pair = $this->pair ? $this->pair->replicate() : new ServerPair;

        if ($this->pair) {
            $pair->id = $this->pair->getKey();
            $pair->exists = true;
        }

        $pair->fill($this->attributesForModel());
        $pair->monitor_key ??= (string) Str::uuid();

        return $pair;
    }

    /**
     * @return array<string, mixed>
     */
    protected function attributesForModel(): array
    {
        $attributes = [
            'name' => $this->name,
            'description' => $this->description ?: null,
            'enabled' => $this->enabled,

            'primary_host' => $this->primary_host,
            'primary_port' => $this->primary_port,
            'primary_username' => $this->primary_username,
            'primary_database' => $this->primary_database,
            'primary_use_tls' => $this->primary_use_tls,

            'replica_host' => $this->replica_host,
            'replica_port' => $this->replica_port,
            'replica_username' => $this->replica_username,
            'replica_database' => $this->replica_database,
            'replica_use_tls' => $this->replica_use_tls,

            'heartbeat_table' => $this->heartbeat_table,
            'lag_threshold_seconds' => $this->lag_threshold_seconds,
            'check_replica_status' => $this->check_replica_status,
            'failures_before_alert' => $this->failures_before_alert,
            'realert_after_minutes' => $this->realert_after_minutes,
            'connect_timeout_seconds' => $this->connect_timeout_seconds,
        ];

        foreach (['primary', 'replica'] as $side) {
            if ($this->{"{$side}_no_password"}) {
                $attributes["{$side}_password"] = '';
            } elseif ($this->{"{$side}_password"} !== '') {
                $attributes["{$side}_password"] = $this->{"{$side}_password"};
            }
        }

        return $attributes;
    }
}
