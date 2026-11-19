<?php

namespace Tests\Unit;

use App\Services\NominatimGeocoder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NominatimGeocoderTest extends TestCase
{
    private NominatimGeocoder $geocoder;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.default' => 'array',
            'maps.nominatim.url' => 'https://nominatim.example.test',
            'maps.nominatim.user_agent' => 'postcard-tracker/1.0 (self-hosted)',
            'maps.nominatim.min_interval_ms' => 0,
        ]);
        Cache::flush();
        Http::preventStrayRequests();
        $this->geocoder = new NominatimGeocoder;
    }

    public function test_builds_full_address_then_postcode_and_city_then_city_queries(): void
    {
        $this->assertSame([
            ['q' => 'Hauptstr. 1, 10115 Berlin, Germany'],
            ['postalcode' => '10115', 'city' => 'Berlin', 'country' => 'Germany'],
            ['city' => 'Berlin', 'country' => 'Germany'],
        ], $this->geocoder->queries("Hauptstr. 1\n10115 Berlin", 'Germany'));

        // A US state code is not taken for the city
        $this->assertSame([
            ['q' => '123 Main St, Springfield, IL 62704, United States'],
            ['postalcode' => '62704', 'city' => 'Springfield', 'country' => 'United States'],
            ['city' => 'Springfield', 'country' => 'United States'],
        ], $this->geocoder->queries('123 Main St, Springfield, IL 62704', 'United States'));

        // UK postcode; no postcode means no postcode query
        $this->assertSame(['postalcode' => 'SW1A 2AA', 'city' => 'London', 'country' => 'United Kingdom'],
            $this->geocoder->queries('10 Downing St, London SW1A 2AA', 'United Kingdom')[1]);
        $this->assertSame([
            ['q' => '1 Rue de Rivoli, Paris, France'],
            ['city' => 'Paris', 'country' => 'France'],
        ], $this->geocoder->queries('1 Rue de Rivoli, Paris', 'France'));
    }

    public function test_sends_a_generic_user_agent_to_the_configured_server(): void
    {
        Http::fake(['nominatim.example.test/search*' => Http::response([['lat' => '52.5321', 'lon' => '13.3849']])]);

        $this->assertSame(['lat' => 52.5321, 'lng' => 13.3849], $this->geocoder->geocode('Hauptstr. 1, 10115 Berlin', 'Germany'));

        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://nominatim.example.test/search?')
            && $r->header('User-Agent') === ['postcard-tracker/1.0 (self-hosted)']
            && $r['format'] === 'jsonv2'
            && (string) $r['limit'] === '1'
            && $r['q'] === 'Hauptstr. 1, 10115 Berlin, Germany');
    }

    public function test_falls_back_to_postcode_and_city_when_the_full_address_is_not_found(): void
    {
        Http::fake(function (Request $r) {
            return isset($r['postalcode'])
                ? Http::response([['lat' => '52.53', 'lon' => '13.38']])
                : Http::response([]);
        });

        $this->assertSame(['lat' => 52.53, 'lng' => 13.38], $this->geocoder->geocode('Flat 4, Hauptstr. 1, 10115 Berlin', 'Germany'));
        Http::assertSentCount(2);
    }

    public function test_returns_zeros_when_nothing_matches(): void
    {
        Http::fake(['*' => Http::response([])]);

        $this->assertSame(['lat' => 0.0, 'lng' => 0.0], $this->geocoder->geocode('Nowhere 1, 99999 Atlantis', 'Atlantis'));
        Http::assertSentCount(3);
    }

    public function test_results_and_misses_are_cached(): void
    {
        Http::fake(function (Request $r) {
            return str_contains((string) ($r['q'] ?? ''), 'Berlin') ? Http::response([['lat' => '52.5', 'lon' => '13.4']]) : Http::response([]);
        });

        $this->geocoder->geocode('Berlin', 'Germany');
        $this->geocoder->geocode('Berlin', 'Germany');
        $this->geocoder->geocode('Atlantis', '');
        $this->geocoder->geocode('Atlantis', '');

        Http::assertSentCount(2); // one per distinct query, repeats come from the cache
    }

    public function test_errors_are_not_cached_as_misses(): void
    {
        Http::fake(['*' => Http::response('Too Many Requests', 429)]);

        $this->geocoder->geocode('Berlin', 'Germany');
        $this->geocoder->geocode('Berlin', 'Germany');

        Http::assertSentCount(2);
    }

    public function test_keeps_the_minimum_gap_between_requests(): void
    {
        config(['maps.nominatim.min_interval_ms' => 300]);
        Http::fake(['*' => Http::response([['lat' => '1', 'lon' => '2']])]);

        $start = microtime(true);
        $this->geocoder->geocode('Berlin', 'Germany');
        $this->geocoder->geocode('Paris', 'France');
        $this->geocoder->geocode('Rome', 'Italy');

        $this->assertGreaterThanOrEqual(0.6, microtime(true) - $start); // 3 requests, 2 gaps
    }
}
