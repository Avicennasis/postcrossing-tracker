<?php

namespace Tests\Feature;

use App\Livewire\ImportPostcards;
use App\Models\Contact;
use App\Models\Country;
use App\Models\Postcard;
use App\Models\User;
use App\Services\GeocodingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class MapProviderTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Postcard $card;

    protected function setUp(): void
    {
        parent::setUp();

        config(['currency.base' => 'USD']);
        $this->withoutVite();
        Http::preventStrayRequests();

        $this->user = User::create(['username' => 'tester', 'password' => 'secret']);
        $germany = Country::create(['nama_indonesia' => 'Jerman', 'nama_inggris' => 'Germany', 'kode_iso' => 'DE']);
        $contact = Contact::create(['user_id' => $this->user->id, 'nama_kontak' => 'Anna', 'alamat' => 'Hauptstr. 1, Berlin', 'country_id' => $germany->id, 'lat' => 52.52, 'lng' => 13.40]);
        $this->card = Postcard::create([
            'user_id' => $this->user->id, 'uid' => (string) Str::ulid(), 'type' => 'sent', 'postcard_id' => 'US-1',
            'contact_id' => $contact->id, 'country_id' => $germany->id, 'tanggal_kirim' => '2024-03-15', 'foto_depan' => 'storage/uploads/x.jpg',
        ]);
        Postcard::create([
            'user_id' => $this->user->id, 'uid' => (string) Str::ulid(), 'type' => 'received', 'postcard_id' => 'DE-1',
            'contact_id' => $contact->id, 'country_id' => $germany->id, 'tanggal_kirim' => '2024-03-15', 'foto_depan' => 'storage/uploads/y.jpg',
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function pages(): array
    {
        $this->actingAs($this->user);

        return [
            'postcard' => $this->get(route('view', ['id' => $this->card->id]))->assertOk()->getContent(),
            'dashboard' => $this->get(route('dashboard'))->assertOk()->getContent(),
            'arrival' => $this->get(route('receive.confirm', ['uid' => $this->card->uid]))->assertOk()->getContent(),
            'gallery' => $this->get(route('gallery'))->assertOk()->getContent(),
        ];
    }

    public function test_leaflet_is_the_default_and_shows_osm_attribution(): void
    {
        $this->assertSame('leaflet', config('maps.provider'));

        foreach ($this->pages() as $page => $html) {
            $this->assertStringContainsString('vendor/leaflet/leaflet.js', $html, $page);
            $this->assertStringContainsString('js/pc-leaflet.js', $html, $page);
            // Attribution link (JSON-escaped inside @js, so allow any slash escaping)
            $this->assertMatchesRegularExpression('#openstreetmap\.org[\\\\/]+copyright#', $html, $page);
            $this->assertStringContainsString('OpenStreetMap', $html, $page);
            $this->assertStringContainsString('tile.openstreetmap.org', $html, $page);
            $this->assertStringNotContainsString('maps.googleapis.com', $html, $page);
        }
    }

    public function test_google_provider_keeps_the_google_maps_path(): void
    {
        config(['maps.provider' => 'google']);

        foreach ($this->pages() as $page => $html) {
            $this->assertStringContainsString('maps.googleapis.com', $html, $page);
            $this->assertStringNotContainsString('vendor/leaflet/leaflet.js', $html, $page);
        }
    }

    public function test_geocoding_uses_nominatim_with_the_english_country_name(): void
    {
        $this->geocodeResult = ['lat' => 52.5, 'lng' => 13.4];

        $coords = app(GeocodingService::class)->getCoordinates("Hauptstr. 1\n10115 Berlin", 'Jerman');

        $this->assertSame(['lat' => 52.5, 'lng' => 13.4], $coords);
        $this->assertSame([['Hauptstr. 1 10115 Berlin', 'Germany']], $this->geocodeCalls);
    }

    public function test_google_provider_geocodes_with_google(): void
    {
        config(['maps.provider' => 'google', 'app.google_api_key' => 'test-key']);
        Http::fake(['maps.googleapis.com/maps/api/place/textsearch/*' => Http::response([
            'status' => 'OK', 'results' => [['geometry' => ['location' => ['lat' => 1.5, 'lng' => 2.5]]]],
        ])]);

        $this->assertSame(['lat' => 1.5, 'lng' => 2.5], (new GeocodingService)->getCoordinates('Somewhere 1', 'Germany'));
        $this->assertSame([], $this->geocodeCalls);
    }

    public function test_csv_import_stops_geocoding_when_its_time_budget_is_used(): void
    {
        config(['maps.import_geocode_budget' => 0]);
        Storage::fake('local');
        $this->actingAs($this->user);

        $csv = "header\nsent;US-2;15/03/2024;;Card;Ben;Main St 1, Hamburg;Germany;-;1.65;USD\n";

        Livewire::test(ImportPostcards::class)
            ->set('file_csv', UploadedFile::fake()->createWithContent('cards.csv', $csv))
            ->call('import')
            ->assertSet('messageType', 'success')
            ->assertSet('message', 'Successfully imported 1 records! 1 address(es) still need map coordinates: run `php artisan postcards:geocode-missing` or use Refresh Location on the card.');

        $this->assertSame([], $this->geocodeCalls);
    }

    public function test_geocode_missing_command_fills_in_contacts_without_coordinates(): void
    {
        $missing = Contact::create(['user_id' => $this->user->id, 'nama_kontak' => 'Ben', 'alamat' => 'Main St 1, Hamburg', 'country_id' => $this->card->country_id, 'lat' => 0, 'lng' => 0]);
        $this->geocodeResult = ['lat' => 53.55, 'lng' => 9.99];

        $this->artisan('postcards:geocode-missing')
            ->expectsOutput('Looked up 1 contact(s); found coordinates for 1.')
            ->assertSuccessful();

        $this->assertSame(53.55, $missing->fresh()->lat);
        $this->assertSame([['Main St 1, Hamburg', 'Germany']], $this->geocodeCalls); // Anna already had coordinates
    }
}
