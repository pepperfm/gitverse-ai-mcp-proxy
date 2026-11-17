<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;
use Inertia\Inertia;
use Inertia\Response;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        \Illuminate\Support\Carbon::setLocale(config('app.locale'));

        // Only the MCP-specific Passport endpoints are exposed; no unused grant routes.
        Passport::ignoreRoutes();
        Passport::$deviceCodeGrantEnabled = false;
    }

    public function boot(UrlGenerator $url): void
    {
        if (!$this->app->isLocal() && !$this->app->runningInConsole()) {
            $url->forceScheme('https');
        }

        Model::unguard();
        Model::shouldBeStrict($this->app->isLocal());

        Passport::tokensCan(['gitverse:access' => 'Access your GitVerse repositories']);
        Passport::tokensExpireIn(now()->addHour());
        Passport::refreshTokensExpireIn(now()->addDays(30));
        Passport::authorizationView(static fn (array $parameters): Response => Inertia::render('Connect', [
            'mode' => 'consent',
            'clientName' => (string) $parameters['client']->name,
            'callbackHost' => (string) parse_url($parameters['request']->query('redirect_uri'), PHP_URL_HOST),
            'authToken' => $parameters['authToken'],
        ]));

        RateLimiter::for(
            'api',
            static fn(Request $request) => Limit::perMinute(60)->by($request->user()?->id ?: $request->ip()),
        );
    }
}
