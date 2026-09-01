<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureUrlScheme();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * An https APP_URL means https links, whatever the proxy says.
     *
     * `trustProxies` in bootstrap/app.php covers the ordinary case, where the
     * thing in front sets X-Forwarded-Proto. It is not enough by itself: put a
     * CDN or a load balancer in front of *that* which terminates TLS and speaks
     * plain http onward, and the proxy truthfully forwards
     * `X-Forwarded-Proto: http`. The app then builds http asset URLs, the
     * browser blocks every one of them as mixed content, and the login page
     * renders as unstyled text with nothing in any server log to say why.
     *
     * APP_URL is the operator stating what the browser actually sees. Take them
     * at their word — it is the one source here that no hop in between can get
     * wrong.
     */
    protected function configureUrlScheme(): void
    {
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }
    }
}
