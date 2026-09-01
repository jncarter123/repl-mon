<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Where the health endpoint is and how to call it — the two things an operator
 * has to type into Icinga, and the one thing they cannot look up from inside
 * the container without reading the environment.
 *
 * Pure: hand it a URL and a token, it renders. No config, no request.
 */
final readonly class HealthEndpoint
{
    public function __construct(
        public string $url,
        public ?string $token,
    ) {}

    public static function current(): self
    {
        $token = (string) config('replication.health.token');

        return new self(
            url: route('api.health'),
            token: $token === '' ? null : $token,
        );
    }

    /**
     * No token, no endpoint: the route 404s, so there is nothing to show an
     * operator but how to switch it on.
     */
    public function isConfigured(): bool
    {
        return $this->token !== null;
    }

    public function path(): string
    {
        return (string) (parse_url($this->url, PHP_URL_PATH) ?: '/api/health');
    }

    /**
     * A check_http line to paste, with the token left as a shell variable —
     * this is on a screen, and a command with the secret already in it is a
     * command that ends up in a shell history and a ticket.
     */
    public function checkCommand(): string
    {
        $parts = parse_url($this->url);

        $host = is_array($parts) && isset($parts['host']) ? (string) $parts['host'] : 'localhost';
        $port = is_array($parts) && isset($parts['port']) ? (int) $parts['port'] : null;
        $ssl = is_array($parts) && ($parts['scheme'] ?? 'http') === 'https';

        $command = "check_http -H {$host}";

        if ($port !== null) {
            $command .= " -p {$port}";
        }

        if ($ssl) {
            $command .= ' --ssl';
        }

        return $command." -u {$this->path()} \\\n"
            .'    -k "X-Health-Token: $TOKEN" -s "REPLICATION OK"';
    }

    /**
     * The same check by hand. 503 is the answer to expect while anything is
     * wrong, so `-f` would hide exactly what you are looking for.
     */
    public function curlCommand(): string
    {
        return "curl -sS -H \"X-Health-Token: \$TOKEN\" {$this->url}";
    }
}
