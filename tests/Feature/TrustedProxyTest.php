<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\URL;

// TLS is terminated at the reverse proxy; the container speaks plain http on
// 8080. Everything here is about the app believing the proxy about that, since
// the symptom of getting it wrong — http:// asset and redirect URLs on an
// https site — only shows up in a browser, never in this suite.

it('believes the proxy that the request arrived over https', function () {
    $this->get('/up', ['X-Forwarded-Proto' => 'https']);

    expect(request()->isSecure())->toBeTrue()
        ->and(request()->getScheme())->toBe('https');
});

it('builds https urls for an https request', function () {
    $this->get('/up', [
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-Host' => 'repl-monitor.example.com',
    ]);

    expect(url('/login'))->toBe('https://repl-monitor.example.com/login');
});

it('reads the client address out of the forwarded-for header', function () {
    // Not cosmetic: it is what the throttle middleware on the login form keys
    // on, and without it every attempt shares the proxy's address.
    $this->get('/up', ['X-Forwarded-For' => '203.0.113.7']);

    expect(request()->ip())->toBe('203.0.113.7');
});

it('stays on http when nothing in front says otherwise', function () {
    // An absolute url rather than '/up': the base url a test request inherits
    // comes from APP_URL, which is https in most of these environments, and
    // that would decide this assertion instead of the header we are testing.
    $this->get('http://repl-monitor.test/up');

    expect(request()->isSecure())->toBeFalse();
});

// The belt to trustProxies' braces. A CDN or load balancer in front of the
// proxy that terminates TLS and speaks plain http onward makes the proxy
// forward `X-Forwarded-Proto: http` — truthfully, from where it sits — and the
// headers above then argue for exactly the wrong scheme.

it('builds https urls from an https APP_URL even when the proxy says http', function () {
    // Reset first: the real boot has already read this environment's own
    // APP_URL, and forcing a scheme does not un-force on a second boot.
    URL::forceScheme(null);
    config()->set('app.url', 'https://repl-monitor.example.com');
    (new AppServiceProvider(app()))->boot();

    $this->get('http://repl-monitor.test/up', ['X-Forwarded-Proto' => 'http']);

    expect(url('/login'))->toStartWith('https://');
});

it('leaves the scheme alone for an http APP_URL', function () {
    // Otherwise every `php artisan serve` and every local docker run starts
    // emitting https links to a port that has never spoken it.
    URL::forceScheme(null);
    config()->set('app.url', 'http://localhost:8000');
    (new AppServiceProvider(app()))->boot();

    $this->get('http://repl-monitor.test/up');

    expect(url('/login'))->toStartWith('http://');
});
