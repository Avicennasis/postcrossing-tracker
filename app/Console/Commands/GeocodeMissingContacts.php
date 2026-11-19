<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Services\GeocodingService;
use Illuminate\Console\Command;

/**
 * Fill in map coordinates for contacts that have none, e.g. after a large
 * CSV import ran out of its geocoding time budget. Lookups go through the
 * same rate-limited geocoder, so this can run for a while on big imports.
 */
class GeocodeMissingContacts extends Command
{
    protected $signature = 'postcards:geocode-missing {--limit=0 : Stop after this many lookups (0 = no limit)}';

    protected $description = 'Look up map coordinates for contacts that have none (rate-limited)';

    public function handle(GeocodingService $geocoder): int
    {
        $limit = max(0, (int) $this->option('limit'));
        $looked = 0;
        $found = 0;

        Contact::with('country')->orderBy('id')->chunkById(100, function ($contacts) use ($geocoder, $limit, &$looked, &$found) {
            foreach ($contacts as $contact) {
                if (($contact->lat != 0 || $contact->lng != 0) || blank($contact->alamat)) {
                    continue;
                }
                if ($limit > 0 && $looked >= $limit) {
                    return false;
                }

                $looked++;
                $coords = $geocoder->getCoordinates($contact->alamat, $contact->country?->display_name ?? '');

                if ($coords['lat'] != 0 || $coords['lng'] != 0) {
                    $contact->lat = $coords['lat'];
                    $contact->lng = $coords['lng'];
                    $contact->save();
                    $found++;
                }
            }
        });

        $this->info("Looked up $looked contact(s); found coordinates for $found.");

        return self::SUCCESS;
    }
}
