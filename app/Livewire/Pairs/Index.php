<?php

declare(strict_types=1);

namespace App\Livewire\Pairs;

use App\Models\ServerPair;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * @property-read Collection<int, ServerPair> $pairs
 */
#[Title('Server pairs')]
class Index extends Component
{
    public string $search = '';

    /**
     * @return Collection<int, ServerPair>
     */
    #[Computed]
    public function pairs(): Collection
    {
        return ServerPair::query()
            ->withCount('recipients')
            ->when($this->search !== '', fn ($query) => $query->where(
                fn ($q) => $q->where('name', 'like', "%{$this->search}%")
                    ->orWhere('primary_host', 'like', "%{$this->search}%")
                    ->orWhere('replica_host', 'like', "%{$this->search}%")
            ))
            ->orderBy('name')
            ->get();
    }

    public function toggle(int $pairId): void
    {
        $pair = ServerPair::findOrFail($pairId);
        $pair->enabled = ! $pair->enabled;

        if (! $pair->enabled) {
            // A pair switched off mid-outage must not send a recovery email
            // when it comes back, nor resume its reminder clock.
            $pair->alerting = false;
            $pair->consecutive_failures = 0;
            $pair->failing_since = null;
        }

        $pair->save();

        Flux::toast(
            variant: 'success',
            text: $pair->enabled ? "{$pair->name} is being checked again." : "{$pair->name} is no longer being checked.",
        );

        unset($this->pairs);
    }

    public function delete(int $pairId): void
    {
        $pair = ServerPair::findOrFail($pairId);
        $name = $pair->name;

        // Checks, alerts and per-pair recipients go with it (cascade). The
        // heartbeat row on the customer's primary is left alone — deleting from
        // their database is not this app's call to make.
        $pair->delete();

        Flux::toast(variant: 'success', text: "{$name} deleted. Its heartbeat row is still on the primary.");

        unset($this->pairs);
    }

    public function render(): View
    {
        return view('livewire.pairs.index');
    }
}
