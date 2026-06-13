<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class UserCacheService
{
    public const TTL_BALANCE = 300;
    public const TTL_ANALYTICS = 300;
    public const TTL_BUDGET_STATUS = 300;
    public const TTL_TREND = 600;
    public const TTL_FINANCIAL_CONTEXT = 120;

    /**
     * Get the current cache version for a user.
     */
    public function getVersion(int $userId): int
    {
        return (int) Cache::get("user:{$userId}:financial_version", 1);
    }

    /**
     * Delete all financial caches for the user.
     * Called on transaction/budget changes.
     */
    public function flushFinancialCache(int $userId): void
    {
        // Increment the financial version to invalidate all versioned keys
        // This is safe for both redis and file drivers
        Cache::increment("user:{$userId}:financial_version");
        
        // Ensure version is initialized if it wasn't
        if (!Cache::has("user:{$userId}:financial_version")) {
            Cache::put("user:{$userId}:financial_version", 2);
        }
    }
}
