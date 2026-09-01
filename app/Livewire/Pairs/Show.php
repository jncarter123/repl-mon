<?php

declare(strict_types=1);

namespace App\Livewire\Pairs;

use App\Models\AlertRecipient;
use App\Models\ReplicationAlert;
use App\Models\ReplicationCheck;
use App\Models\ServerPair;
use App\Services\ReplicationChecker;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Throwable;

/**
 * @property-read Collection<int, ReplicationCheck> $checks
 * @property-read Collection<int, ReplicationAlert> $alerts
 * @property-read Collection<int, AlertRecipient> $ownRecipients
 * @property-read Collection<int, AlertRecipient> $effectiveRecipients
 * @property-read bool $usingGlobalList
 */
class Show extends Component
{
    public ServerPair $pair;

    public string $recipientName = '';

    public string $recipientEmail = '';

    public function mount(ServerPair $pair): void
    {
        $this->pair = $pair;
    }

    public function title(): string
    {
        return $this->pair->name;
    }

    /**
     * @return Collection<int, ReplicationCheck>
     */
    #[Computed]
    public function checks(): Collection
    {
        return $this->pair->checks()->latest('checked_at')->limit(60)->get();
    }

    /**
     * @return Collection<int, ReplicationAlert>
     */
    #[Computed]
    public function alerts(): Collection
    {
        return $this->pair->alerts()->latest('sent_at')->limit(10)->get();
    }

    /**
     * @return Collection<int, AlertRecipient>
     */
    #[Computed]
    public function ownRecipients(): Collection
    {
        return $this->pair->recipients()->orderBy('email')->get();
    }

    /**
     * @return Collection<int, AlertRecipient>
     */
    #[Computed]
    public function effectiveRecipients(): Collection
    {
        return $this->pair->resolvedRecipients();
    }

    #[Computed]
    public function usingGlobalList(): bool
    {
        return $this->pair->usesGlobalRecipients();
    }

    public function addRecipient(): void
    {
        $this->validate([
            'recipientEmail' => [
                'required', 'email', 'max:191',
                Rule::unique('alert_recipients', 'email')->where('server_pair_id', $this->pair->getKey()),
            ],
            'recipientName' => ['nullable', 'string', 'max:191'],
        ], attributes: ['recipientEmail' => 'email address']);

        $this->pair->recipients()->create([
            'name' => $this->recipientName ?: null,
            'email' => $this->recipientEmail,
            'enabled' => true,
        ]);

        $this->reset('recipientName', 'recipientEmail');
        unset($this->ownRecipients, $this->effectiveRecipients, $this->usingGlobalList);

        Flux::toast(variant: 'success', text: 'Recipient added. This pair no longer uses the global list.');
    }

    public function removeRecipient(int $recipientId): void
    {
        $this->pair->recipients()->whereKey($recipientId)->delete();

        unset($this->ownRecipients, $this->effectiveRecipients, $this->usingGlobalList);

        Flux::toast(variant: 'success', text: 'Recipient removed.');
    }

    public function checkNow(ReplicationChecker $checker): void
    {
        try {
            $check = $checker->check($this->pair);

            Flux::toast(
                variant: $check->status->isProblem() ? 'danger' : 'success',
                heading: $check->status->label(),
                text: $check->message,
            );
        } catch (Throwable $e) {
            Flux::toast(variant: 'danger', heading: 'Check failed', text: $e->getMessage());
        }

        $this->pair->refresh();
        unset($this->checks, $this->alerts);
    }

    public function render(): View
    {
        return view('livewire.pairs.show');
    }
}
