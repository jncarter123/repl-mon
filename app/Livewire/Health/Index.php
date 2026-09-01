<?php

declare(strict_types=1);

namespace App\Livewire\Health;

use App\Models\HealthToken;
use App\Services\HealthTokens;
use App\Support\HealthEndpoint;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The other direction: not what this app watches, but how something else
 * watches it. A monitor nobody has pointed anything at is the state the health
 * endpoint exists to prevent, so the URL and its tokens get a page of their own
 * rather than a footnote on the dashboard.
 *
 * @property-read HealthEndpoint $health
 * @property-read Collection<int, HealthToken> $tokens
 * @property-read string|null $environmentToken
 */
#[Title('Health endpoint')]
class Index extends Component
{
    /** Optional label for the token about to be issued. */
    public string $tokenName = '';

    #[Computed]
    public function health(): HealthEndpoint
    {
        return HealthEndpoint::current();
    }

    /**
     * @return Collection<int, HealthToken>
     */
    #[Computed]
    public function tokens(): Collection
    {
        return HealthToken::query()->orderBy('created_at')->get();
    }

    /**
     * `REPL_HEALTH_TOKEN`, if it is set. It keeps working — it just cannot be
     * rotated from in here, because this app does not write to its own
     * environment.
     */
    #[Computed]
    public function environmentToken(): ?string
    {
        return app(HealthTokens::class)->environmentToken();
    }

    public function generateToken(HealthTokens $tokens): void
    {
        $this->validate(['tokenName' => ['nullable', 'string', 'max:60']]);

        $token = $tokens->issue($this->tokenName);

        $this->tokenName = '';

        unset($this->tokens);

        Flux::toast(
            variant: 'success',
            heading: $token->name,
            text: 'Copy it into the check command. It stays readable here, so there is no rush.',
        );
    }

    /**
     * Rotation is: issue the new one, move the checks over, then delete this.
     * Deleting the last one switches the endpoint off entirely — it goes back
     * to answering 404.
     */
    public function revokeToken(int $tokenId, HealthTokens $tokens): void
    {
        $tokens->revoke($tokenId);

        unset($this->tokens);

        Flux::toast(variant: 'success', text: 'Token deleted. Anything still using it now gets a 401.');
    }

    public function render(): View
    {
        return view('livewire.health.index');
    }
}
