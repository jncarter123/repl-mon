<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\HealthTokens;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The health endpoint has to be readable by a monitoring system that has no
 * session, and it names your pairs and their state. So it takes a shared secret
 * and nothing else.
 *
 * No token in existence — none in the environment, none issued from the
 * dashboard — means the endpoint does not exist: 404, like the registration
 * page, so an instance nobody set this up on is not quietly serving its pair
 * list to whoever finds the port. A *wrong* token is a 401: that one is
 * somebody's misconfigured check command, and telling them apart is worth more
 * than the little the distinction gives away.
 */
class RequireHealthToken
{
    public function __construct(protected HealthTokens $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->tokens->anyExist()) {
            abort(404);
        }

        if (! $this->tokens->verify($this->presented($request))) {
            abort(401, 'REPLICATION UNKNOWN - health token missing or wrong');
        }

        return $next($request);
    }

    /**
     * Header first, query string second: a token in a URL ends up in access
     * logs, but some check commands can only send a URL.
     */
    protected function presented(Request $request): string
    {
        return $request->bearerToken()
            ?? $request->header('X-Health-Token')
            ?? $request->string('token')->toString();
    }
}
