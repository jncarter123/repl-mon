<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\HealthToken;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Issues and checks the secrets that open `GET /api/health`.
 *
 * Two sources, both valid: `REPL_HEALTH_TOKEN` for a deployment that would
 * rather hold its secrets in the environment, and tokens issued from the
 * dashboard for one that would rather not have to edit a file and restart the
 * container to rotate one. Several may exist at once — that is what makes a
 * rotation a rotation rather than an outage.
 */
class HealthTokens
{
    /** 24 bytes, hex, so it reads the same as `openssl rand -hex 24` in the docs. */
    public const BYTES = 24;

    public function issue(?string $name = null): HealthToken
    {
        $name = trim((string) $name);

        return HealthToken::query()->create([
            'name' => $name !== '' ? $name : 'token '.(HealthToken::query()->count() + 1),
            'token' => bin2hex(random_bytes(self::BYTES)),
        ]);
    }

    public function revoke(int $id): void
    {
        HealthToken::query()->whereKey($id)->delete();
    }

    /**
     * Is there any way in at all? None means the route does not exist — an
     * endpoint that anyone can read names every pair and its state.
     */
    public function anyExist(): bool
    {
        return $this->environmentToken() !== null || HealthToken::query()->exists();
    }

    public function environmentToken(): ?string
    {
        $token = (string) config('replication.health.token');

        return $token === '' ? null : $token;
    }

    public function verify(string $presented): bool
    {
        if ($presented === '') {
            return false;
        }

        $environment = $this->environmentToken();

        if ($environment !== null && hash_equals($environment, $presented)) {
            return true;
        }

        foreach (HealthToken::query()->get() as $token) {
            try {
                // Decrypted here rather than read off the cast, so the one way
                // this can fail is visible: a token encrypted with an APP_KEY
                // that is no longer the one in use.
                $stored = Crypt::decryptString((string) $token->getRawOriginal('token'));
            } catch (DecryptException) {
                // The APP_KEY that encrypted this one is gone. Say so rather
                // than failing the whole check: the other tokens still work,
                // and this is the same key that decrypts the pairs' passwords,
                // so it is worth a line in the log.
                Log::warning('A health token could not be decrypted with this APP_KEY.', [
                    'health_token_id' => $token->getKey(),
                ]);

                continue;
            }

            if (hash_equals($stored, $presented)) {
                $this->stampUsage($token);

                return true;
            }
        }

        return false;
    }

    /**
     * Once a minute at most. The endpoint is polled on a timer, and the useful
     * question is "is anything polling this at all?", not "exactly when".
     */
    protected function stampUsage(HealthToken $token): void
    {
        if ($token->last_used_at !== null && $token->last_used_at->addMinute()->isFuture()) {
            return;
        }

        $token->timestamps = false;
        $token->last_used_at = now();
        $token->save();
    }
}
