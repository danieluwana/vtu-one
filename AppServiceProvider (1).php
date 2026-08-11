<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Http\Request;
use App\Contracts\VtuProviderInterface;
use App\Services\VtuProviderFactory;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
            // Bind VTU provider — resolves active provider from settings DB
        $this->app->bind(VtuProviderInterface::class, function () {
            return VtuProviderFactory::make();
        });
    
        // Also bind VtuService so it receives the interface
        $this->app->bind(\App\Services\VtuService::class, function ($app) {
            return new \App\Services\VtuService(
                $app->make(VtuProviderInterface::class),
            );
        });
    }

    public function boot(): void
    {
        // Safety check — ensure debug is off in production
        if (app()->isProduction() && config('app.debug')) {
            throw new \RuntimeException('APP_DEBUG must be false in production!');
        }

        // ── Policies ─────────────────────────────────────────────────────────
        Gate::policy(\App\Models\User::class, \App\Policies\UserPolicy::class);
        Gate::policy(\App\Models\WalletTransaction::class, \App\Policies\TransactionPolicy::class);

        // ── Rate Limiters ─────────────────────────────────────────────────────
        // Auth routes — 5 attempts per minute per IP
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(5)
                ->by($request->input('email') . '|' . $request->ip());
        });

        // API routes — 60 requests per minute per user
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)
                ->by($request->user()?->id ?: $request->ip());
        });

        // OTP — 3 attempts per minute per IP
        RateLimiter::for('otp', function (Request $request) {
            return Limit::perMinute(3)
                ->by($request->ip());
        });
    }
}