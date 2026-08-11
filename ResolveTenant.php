<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * ResolveTenant Middleware
 *
 * Single-tenant mode for live server deployment.
 *
 * Strategy:
 *  - Reads TENANT_ID from .env (default: 1)
 *  - Loads tenant once and caches for 5 minutes
 *  - Binds as 'currentTenant' singleton for the request
 *  - All models using BelongsToTenant trait will auto-scope
 *
 * No domain resolution needed for single-tenant deployment.
 * If you later need multi-tenancy, this is the only file to change.
 */
class ResolveTenant
{
    private const CACHE_TTL_SECONDS = 300; // 5 minutes

    public function handle(Request $request, Closure $next): Response
    {
        // Skip if already resolved (prevents duplicate resolution)
        if (app()->bound('currentTenant')) {
            return $next($request);
        }

        $tenant = $this->resolveTenant();

        if ($tenant) {
            app()->instance('currentTenant', $tenant);
            $this->applyTenantConfig($tenant);
        }

        return $next($request);
    }

    /**
     * Resolve tenant by ID from config/env.
     * Cached to avoid repeated DB hits per request.
     * Falls back to first tenant if TENANT_ID not set.
     */
    private function resolveTenant(): ?Tenant
    {
        $tenantId = (int) config('vtu.tenancy.tenant_id', env('TENANT_ID', 1));

        $cacheKey = "tenant_id_{$tenantId}";

        // Cache the tenant ID — always fetch fresh model to avoid stale data
        $cachedId = Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($tenantId) {
            $tenant = Tenant::find($tenantId);

            // Fallback to first tenant if specific ID not found
            if (! $tenant) {
                $tenant = Tenant::first();
            }

            return $tenant?->id;
        });

        if (! $cachedId) {
            return null;
        }

        return Tenant::find($cachedId);
    }

    /**
     * Apply tenant config to the running request.
     * Makes site name, colors, features available via config() and helpers.
     */
    private function applyTenantConfig(Tenant $tenant): void
    {
        // Site name
        $siteName = $tenant->getSetting('site_name', $tenant->name ?? config('app.name'));
        config(['app.name' => $siteName]);

        // Timezone
        if ($tenant->timezone) {
            config(['app.timezone' => $tenant->timezone]);
            date_default_timezone_set($tenant->timezone);
        }

        // Branding
        config(['vtu.ui.primary_color'   => $tenant->primary_color   ?? '#0066FF']);
        config(['vtu.ui.secondary_color' => $tenant->secondary_color ?? '#00C6FB']);
        config(['vtu.ui.logo'            => $tenant->logo]);

        // Feature flags — tenant overrides platform defaults
        if (! empty($tenant->features)) {
            foreach ($tenant->features as $feature => $enabled) {
                config(["vtu.features.{$feature}" => $enabled]);
            }
        }

        // Currency
        config(['vtu.currency'        => $tenant->currency        ?? 'NGN']);
        config(['vtu.currency_symbol' => $tenant->currency_symbol ?? '₦']);
    }
}
