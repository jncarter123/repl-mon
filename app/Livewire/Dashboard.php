<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\CheckStatus;
use App\Models\ReplicationAlert;
use App\Models\ServerPair;
use App\Services\ReplicationChecker;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

/**
 * @property-read Collection<int, ServerPair> $pairs
 * @property-read array<string, int> $counts
 * @property-read Collection<int, ReplicationAlert> $recentAlerts
 */
#[Title('Replication status')]
class Dashboard extends Component
{
    /**
     * Worst first. A dashboard that puts the broken pair below the fold sorted
     * by name is a dashboard nobody looks at twice.
     *
     * @return Collection<int, ServerPair>
     */
    #[Computed]
    public function pairs(): Collection
    {
        return ServerPair::query()
            ->with('latestCheck')
            ->orderBy('name')
            ->get()
            ->sortByDesc(fn (ServerPair $pair): int => $pair->enabled ? $pair->current_status->severity() + 1 : 0)
            ->values();
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function counts(): array
    {
        $enabled = $this->pairs->where('enabled', true);

        return [
            'total' => $this->pairs->count(),
            'disabled' => $this->pairs->where('enabled', false)->count(),
            'ok' => $enabled->where('current_status', CheckStatus::Ok)->count(),
            'problem' => $enabled->filter(fn (ServerPair $p): bool => $p->current_status->isProblem())->count(),
            'unknown' => $enabled->where('current_status', CheckStatus::Unknown)->count(),
        ];
    }

    /**
     * @return Collection<int, ReplicationAlert>
     */
    #[Computed]
    public function recentAlerts(): Collection
    {
        return ReplicationAlert::query()
            ->with('serverPair')
            ->latest('sent_at')
            ->limit(8)
            ->get();
    }

    public function checkNow(int $pairId, ReplicationChecker $checker): void
    {
        $pair = ServerPair::findOrFail($pairId);

        try {
            $check = $checker->check($pair);

            Flux::toast(
                variant: $check->status->isProblem() ? 'danger' : 'success',
                heading: $pair->name,
                text: $check->message,
            );
        } catch (Throwable $e) {
            Flux::toast(variant: 'danger', heading: $pair->name, text: $e->getMessage());
        }

        unset($this->pairs, $this->counts, $this->recentAlerts);
    }

    public function render(): View
    {
        return view('livewire.dashboard');
    }
}
