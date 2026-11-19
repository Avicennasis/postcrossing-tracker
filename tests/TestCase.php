<?php

namespace Tests;

use App\Services\NominatimGeocoder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Coordinates the geocoder stub returns; null means "not found".
     *
     * @var array{lat: float, lng: float}|null
     */
    protected ?array $geocodeResult = null;

    /** Addresses passed to the geocoder stub. */
    protected array $geocodeCalls = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Never call a real geocoder from tests; NominatimGeocoderTest builds its own instance.
        $test = $this;
        $this->app->instance(NominatimGeocoder::class, new class($test) extends NominatimGeocoder
        {
            public function __construct(private TestCase $test) {}

            public function geocode(string $address, string $country): array
            {
                return $this->test->recordGeocode($address, $country);
            }
        });
    }

    /**
     * @return array{lat: float, lng: float}
     */
    public function recordGeocode(string $address, string $country): array
    {
        $this->geocodeCalls[] = [$address, $country];

        return $this->geocodeResult ?? ['lat' => 0.0, 'lng' => 0.0];
    }
}
