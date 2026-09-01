<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * This app stores credentials for production database servers, so open
 * registration is not a feature it can have. The sign-up screen exists to
 * create the very first operator and then stops existing — 404 rather than
 * 403, so it does not advertise itself to anyone probing.
 *
 * Further accounts are made by an existing operator with
 * `php artisan replication:add-user`.
 */
class RegistrationIsFirstRunOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        // Both halves: the form is named `register`, the endpoint that
        // actually creates the account is `register.store`.
        if ($request->routeIs('register*') && User::query()->exists()) {
            abort(404);
        }

        return $next($request);
    }
}
