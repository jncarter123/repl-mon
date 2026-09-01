<?php

declare(strict_types=1);

namespace App\Livewire\Recipients;

use App\Models\AlertRecipient;
use App\Models\ServerPair;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * @property-read Collection<int, AlertRecipient> $recipients
 * @property-read Collection<int, ServerPair> $pairsWithOwnLists
 */
#[Title('Alert recipients')]
class Index extends Component
{
    public string $name = '';

    public string $email = '';

    /**
     * @return Collection<int, AlertRecipient>
     */
    #[Computed]
    public function recipients(): Collection
    {
        return AlertRecipient::query()->global()->orderBy('email')->get();
    }

    /**
     * Pairs that have named their own recipients do not fall back here, and
     * saying so on screen beats someone discovering it during an outage.
     *
     * @return Collection<int, ServerPair>
     */
    #[Computed]
    public function pairsWithOwnLists(): Collection
    {
        return ServerPair::query()
            ->whereHas('recipients', fn ($query) => $query->where('enabled', true))
            ->orderBy('name')
            ->get();
    }

    public function add(): void
    {
        $this->validate([
            'email' => ['required', 'email', 'max:191', Rule::unique('alert_recipients', 'email')->whereNull('server_pair_id')],
            'name' => ['nullable', 'string', 'max:191'],
        ], attributes: ['email' => 'email address']);

        AlertRecipient::create([
            'server_pair_id' => null,
            'name' => $this->name ?: null,
            'email' => $this->email,
            'enabled' => true,
        ]);

        $this->reset('name', 'email');
        unset($this->recipients);

        Flux::toast(variant: 'success', text: 'Recipient added to the global list.');
    }

    public function toggle(int $recipientId): void
    {
        $recipient = AlertRecipient::query()->global()->findOrFail($recipientId);
        $recipient->enabled = ! $recipient->enabled;
        $recipient->save();

        unset($this->recipients);
    }

    public function remove(int $recipientId): void
    {
        AlertRecipient::query()->global()->whereKey($recipientId)->delete();

        unset($this->recipients);

        Flux::toast(variant: 'success', text: 'Recipient removed.');
    }

    public function render(): View
    {
        return view('livewire.recipients.index');
    }
}
