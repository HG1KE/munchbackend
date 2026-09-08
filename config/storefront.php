<?php

return [

    /** Full /api/v1/config response cache (file/database driver). */
    'config_cache_ttl' => (int) env('STOREFRONT_CONFIG_CACHE_TTL', 120),

    /** Log duration, cache hit/miss, approximate payload size (production: false). */
    'instrument_config' => (bool) env('STOREFRONT_INSTRUMENT_CONFIG', false),

];
