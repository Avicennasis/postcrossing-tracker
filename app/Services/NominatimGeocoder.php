<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Address -> coordinates via Nominatim (OpenStreetMap).
 *
 * Follows the public instance's usage policy: one request at a time with a
 * minimum gap shared by every worker (cache lock), an identifying
 * User-Agent, results cached (including misses), and no autocomplete.
 */
class NominatimGeocoder
{
    /**
     * @return array{lat: float, lng: float} zeros when nothing was found
     */
    public function geocode(string $address, string $country): array
    {
        foreach ($this->queries($address, $country) as $query) {
            $result = $this->lookup($query);
            if ($result !== null) {
                return $result;
            }
        }

        return ['lat' => 0.0, 'lng' => 0.0];
    }

    /**
     * Queries from most to least specific: the full address, then postcode +
     * city, then city. Nominatim often misses free-form postal addresses
     * (flat numbers, PO boxes) but finds the postcode or city.
     *
     * @return list<array<string, string>>
     */
    public function queries(string $address, string $country): array
    {
        $address = trim(preg_replace('/\s+/', ' ', str_replace(["\r", "\n"], ', ', $address)));
        $country = trim($country);
        $queries = [];

        if ($address !== '') {
            $queries[] = ['q' => $country !== '' ? "$address, $country" : $address];
        }

        [$postcode, $city] = $this->postcodeAndCity($address, $country);

        if ($postcode !== null && $city !== null) {
            $queries[] = array_filter(['postalcode' => $postcode, 'city' => $city, 'country' => $country]);
        }
        if ($city !== null) {
            $queries[] = array_filter(['city' => $city, 'country' => $country]);
        }

        return array_values(array_unique($queries, SORT_REGULAR));
    }

    /**
     * Best-effort postcode and city from a free-form address: the city is
     * the last comma-separated part that still has letters once any
     * postcode is removed (skipping the country name).
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function postcodeAndCity(string $address, string $country): array
    {
        $parts = array_values(array_filter(array_map('trim', explode(',', $address)), fn ($p) => $p !== ''));
        $parts = array_values(array_filter($parts, fn ($p) => mb_strtolower($p) !== mb_strtolower($country)));

        $postcode = null;
        $city = null;

        foreach (array_reverse($parts) as $part) {
            // e.g. 10115 | SW1A 1AA | 100-0001 | M5V 2T6 | 1012 AB
            if ($postcode === null && preg_match('/\b(\d{3,6}(?:[- ]\d{3,4})?|[A-Z]{1,2}\d[A-Z\d]? ?\d[A-Z]{2}|[A-Z]\d[A-Z] ?\d[A-Z]\d|\d{4} ?[A-Z]{2})\b/i', $part, $m)) {
                $postcode = strtoupper($m[1]);
                $part = trim(str_replace($m[1], '', $part), ' -');
            }
            // Needs a 3+ letter word, so a state code such as "IL" in "Springfield, IL 62704" is skipped
            if ($city === null && preg_match('/\p{L}{3,}/u', $part) && ! preg_match('/^\d/', $part)) {
                $city = $part;
            }
            if ($city !== null && $postcode !== null) {
                break;
            }
        }

        // A lone part is the street address, not a city
        if (count($parts) < 2) {
            $city = null;
        }

        return [$postcode, $city];
    }

    /**
     * One cached, throttled search. Null when Nominatim has no result.
     *
     * @param  array<string, string>  $query
     * @return array{lat: float, lng: float}|null
     */
    private function lookup(array $query): ?array
    {
        $cacheKey = 'nominatim:'.sha1(json_encode($query));
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached['found'] ? ['lat' => $cached['lat'], 'lng' => $cached['lng']] : null;
        }

        $response = $this->throttled(fn () => Http::timeout((int) config('maps.nominatim.timeout', 10))
            ->withHeaders([
                'User-Agent' => (string) config('maps.nominatim.user_agent'),
                'Accept-Language' => 'en',
            ])
            ->get(config('maps.nominatim.url').'/search', $query + ['format' => 'jsonv2', 'limit' => 1]));

        if ($response === null || ! $response->successful()) {
            // Not cached: a rate-limit or outage answer is not a "no result"
            Log::warning('Nominatim lookup failed', ['status' => $response?->status()]);

            return null;
        }

        $hit = $response->json()[0] ?? null;
        $result = isset($hit['lat'], $hit['lon']) ? ['lat' => (float) $hit['lat'], 'lng' => (float) $hit['lon']] : null;

        // Misses are cached for a day so repeated identical queries never hit the server
        Cache::put(
            $cacheKey,
            $result ? ['found' => true] + $result : ['found' => false],
            $result ? now()->addDays((int) config('maps.nominatim.cache_days', 90)) : now()->addDay()
        );

        return $result;
    }

    /**
     * Run $request with at most one Nominatim request in flight across the
     * whole app and a minimum gap since the previous one.
     */
    private function throttled(callable $request)
    {
        $interval = max(0, (int) config('maps.nominatim.min_interval_ms', 1100)) / 1000;

        try {
            return Cache::lock('nominatim:throttle', 30)->block(20, function () use ($request, $interval) {
                $wait = (float) Cache::get('nominatim:last', 0) + $interval - microtime(true);
                if ($wait > 0) {
                    usleep((int) ceil($wait * 1_000_000));
                }

                try {
                    return $request();
                } finally {
                    Cache::put('nominatim:last', microtime(true), 60);
                }
            });
        } catch (\Throwable $e) {
            Log::warning('Nominatim request not sent: '.$e->getMessage());

            return null;
        }
    }
}
