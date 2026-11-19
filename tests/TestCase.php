<?php

namespace Tests;

use App\Services\NominatimGeocoder;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Throwaway key for this test run, generated on first use. */
    private static ?string $testAppKey = null;

    /**
     * Coordinates the geocoder stub returns; null means "not found".
     *
     * @var array{lat: float, lng: float}|null
     */
    protected ?array $geocodeResult = null;

    /** Addresses passed to the geocoder stub. */
    protected array $geocodeCalls = [];

    /**
     * Contact details are encrypted and sessions need a key, but a fresh
     * clone (and CI, which copies .env.example) has an empty APP_KEY. Rather
     * than commit a key, generate a random one for the run when none is set.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        if (blank($app['config']->get('app.key'))) {
            self::$testAppKey ??= 'base64:'.base64_encode(Encrypter::generateKey($app['config']->get('app.cipher')));
            $app['config']->set('app.key', self::$testAppKey);
        }

        return $app;
    }

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
