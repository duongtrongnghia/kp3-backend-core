<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Hook debug mode — logs all hook calls with timing (dev only)
    | Backed by HOOK_DEBUG env var. Read via config() to survive config:cache.
    |--------------------------------------------------------------------------
    */
    'hook_debug' => env('HOOK_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Cache TTLs (seconds) — used by CacheService / TagRegistry / module settings
    |--------------------------------------------------------------------------
    */
    'cache' => [
        'page_ttl' => (int) env('CORE_CACHE_PAGE_TTL', 3600),
        'fragment_ttl' => (int) env('CORE_CACHE_FRAGMENT_TTL', 1800),
        'setting_ttl' => (int) env('CORE_CACHE_SETTING_TTL', 3600),
        'module_ttl' => (int) env('CORE_CACHE_MODULE_TTL', 3600),
        'tag_max_ttl' => (int) env('CORE_CACHE_TAG_MAX_TTL', 86400),
        'driver' => env('CORE_CACHE_DRIVER'),
    ],

];
