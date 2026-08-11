<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;

/**
 * Resolution order: encrypted settings-table value → config()/.env fallback.
 * Every provider service calls this instead of config() directly.
 */
class ProviderCredentialResolver
{
    public static function get(string $provider, string $field, mixed $configFallback = null): mixed
    {
        $key   = "credential_{$provider}_{$field}";
        $value = Setting::getEncrypted($key);

        return ($value !== null && $value !== '') ? $value : $configFallback;
    }

    public static function isConfigured(string $provider, array $fieldsWithFallbacks): bool
    {
        foreach ($fieldsWithFallbacks as $field => $configFallback) {
            if (empty(self::get($provider, $field, $configFallback))) {
                return false;
            }
        }
        return true;
    }
}