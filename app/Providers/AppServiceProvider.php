<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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
        Paginator::useBootstrapFive();

        // Limite por conta autenticada; o serviço continua responsável pela idempotência.
        RateLimiter::for('wallet-actions', fn (Request $request): Limit => Limit::perMinute(12)
            ->by('wallet:'.$request->user()->getAuthIdentifier()));
    }
}
