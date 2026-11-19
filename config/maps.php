<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Map provider
    |--------------------------------------------------------------------------
    |
    | "leaflet" (default): Leaflet with OpenStreetMap tiles and Nominatim
    | geocoding. No API key needed.
    | "google": the Google Maps / AMap integration (needs GOOGLE_API_KEY,
    | and AMAP_* keys for viewers in China).
    |
    */

    'provider' => env('MAP_PROVIDER', 'leaflet'),

    // Tile server and its required attribution (shown bottom-right on every map)
    'tile_url' => env('MAP_TILE_URL', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'),
    'tile_attribution' => env('MAP_TILE_ATTRIBUTION', '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'),

    /*
    |--------------------------------------------------------------------------
    | Nominatim geocoding (used when the provider is "leaflet")
    |--------------------------------------------------------------------------
    |
    | The public instance's policy (operations.osmfoundation.org/policies/nominatim)
    | allows at most 1 request per second for the whole application, requires
    | an identifying User-Agent and caching, and forbids search-as-you-type.
    | Point NOMINATIM_URL at your own instance for heavier use.
    |
    */

    'nominatim' => [
        'url' => rtrim((string) env('NOMINATIM_URL', 'https://nominatim.openstreetmap.org'), '/'),
        'user_agent' => env('NOMINATIM_USER_AGENT', 'postcard-tracker/1.0 (self-hosted)'),
        // Minimum gap between requests, shared by all workers via the cache lock
        'min_interval_ms' => (int) env('NOMINATIM_MIN_INTERVAL_MS', 1100),
        'cache_days' => (int) env('NOMINATIM_CACHE_DAYS', 90),
        'timeout' => 10,
    ],

    // Seconds a CSV import may spend geocoding before leaving the remaining
    // addresses for `php artisan postcards:geocode-missing`.
    'import_geocode_budget' => (int) env('MAP_IMPORT_GEOCODE_BUDGET', 20),

];
