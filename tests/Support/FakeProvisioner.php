<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Data\ProvisionReport;
use App\Models\ServerPair;
use App\Services\HeartbeatProvisioner;

/**
 * Stands in for the real provisioner so the Livewire components around it can be
 * exercised without a server to create anything on.
 */
class FakeProvisioner extends HeartbeatProvisioner
{
    public ?ServerPair $sawPair = null;

    public ?bool $sawIncludeReplica = null;

    public function __construct(public ProvisionReport $report) {}

    public function provision(ServerPair $pair, bool $includeReplica = false): ProvisionReport
    {
        $this->sawPair = $pair;
        $this->sawIncludeReplica = $includeReplica;

        return $this->report;
    }

    public function verify(ServerPair $pair): ProvisionReport
    {
        $this->sawPair = $pair;

        return $this->report;
    }
}
