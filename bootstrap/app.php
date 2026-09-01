<?php

use App\Http\Middleware\RegistrationIsFirstRunOnly;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            RegistrationIsFirstRunOnly::class,
        ]);

        // TLS is terminated at whatever is in front of this (see docker/Caddyfile
        // — the container itself only ever speaks plain HTTP on 8080). Without
        // this, Laravel builds every URL from the scheme it can see, which is
        // http, and an https site then serves http asset and redirect URLs.
        //
        // Trusting `*` is the right call here and not the usual shortcut: the
        // container publishes to loopback, or to nothing at all when the proxy
        // reaches it over the compose network, so the only client that can set
        // these headers is the proxy. Pin it to the proxy's address if you
        // publish the port to the world — but do not publish the port.
        //
        // No HEADER_X_FORWARDED_AWS_ELB: that is the `X-Forwarded-*` dialect
        // for an ELB, and honouring a dialect nothing here speaks is one more
        // header an attacker could reach us with.
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
