<?php

namespace App\Sources;

use App\Models\Hut;
use App\Services\HutReservationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * The Alpenverein (Club Arc Alpin) hut directory — the same clean, complete
 * dataset the official Bettencheck map uses, published as an ArcGIS
 * FeatureServer. One query returns every DAV/ÖAV/AVS hut with name, club,
 * altitude, phone/email/homepage, WGS84 coordinates, and — crucially — the
 * `ohrs_hut_id` linking to the hut-reservation.org online booking system.
 *
 * A hut with an ohrs id is online-bookable (availability fetched from
 * hut-reservation.org); one without is book-direct (phone/email only). This
 * replaces the old approach of blind-enumerating hut ids and parsing five
 * coordinate formats.
 */
class AlpenvereinSource extends AbstractHutSource
{
    private const CACHE = 'caa-cache/huts.json';

    private const CACHE_TTL = 604800; // 7 days — the directory changes slowly

    private const SERVICE = 'https://services1.arcgis.com/PHS4LHADrqt5glC9/ArcGIS/rest/services/AVT_GEO_CAA_HUETTEN_View_P/FeatureServer/0/query';

    public function __construct(private readonly HutReservationService $api) {}

    public function key(): string
    {
        return 'alpenverein';
    }

    public function label(): string
    {
        return 'Alpenverein (CAA hut directory)';
    }

    public function syncCatalog(): int
    {
        $features = $this->fetch();
        if ($features === []) {
            return 0;
        }

        // Dedupe against other sources (e.g. an opted-out section hut that is
        // book-direct here but bookable on huetten-holiday — keep the bookable one).
        $others = $this->otherSourcePoints();

        $kept = 0;
        foreach ($features as $feature) {
            $geometry = $feature['geometry'] ?? [];
            $attrs = $feature['attributes'] ?? [];
            $lat = $geometry['y'] ?? null;
            $lon = $geometry['x'] ?? null;
            if ($lat === null || $lon === null) {
                continue;
            }
            $lat = (float) $lat;
            $lon = (float) $lon;

            if (! $this->inAustria($lat, $lon) || $this->near($lat, $lon, $others)) {
                continue;
            }

            $ohrs = is_numeric($attrs['ohrs_hut_id'] ?? null) ? (int) $attrs['ohrs_hut_id'] : null;

            $this->upsertHut($ohrs ?? $this->hashId((string) ($attrs['id'] ?? '')), [
                'bookable_online' => $ohrs !== null,
                'name' => trim((string) ($attrs['name'] ?? 'Hut')),
                'country' => 'AT',
                'club' => $this->clean($attrs['verein_name'] ?? null),
                'latitude' => $lat,
                'longitude' => $lon,
                'altitude' => is_numeric($attrs['meereshoehe'] ?? null) ? (int) $attrs['meereshoehe'] : null,
                'phone' => $this->clean($attrs['telefon'] ?? null),
                'email' => $this->clean($attrs['email'] ?? null),
                'website' => $this->clean($attrs['homepage'] ?? null),
                'booking_url' => $ohrs !== null
                    ? "https://www.hut-reservation.org/reservation/book-hut/{$ohrs}/wizard"
                    : null,
            ]);
            $kept++;
        }

        return $kept;
    }

    public function syncAvailability(int $days): int
    {
        [$today, $horizon] = $this->window($days);
        $written = 0;

        // Only the online-bookable huts; their id IS the ohrs hut id.
        $huts = Hut::query()
            ->where('source', $this->key())
            ->where('bookable_online', true)
            ->orderBy('id')
            ->get();

        foreach ($huts as $hut) {
            $nights = [];
            foreach ($this->api->availability($hut->id) as $day) {
                $date = isset($day['date']) ? CarbonImmutable::parse($day['date']) : null;
                if ($date === null || $date->lt($today) || $date->gt($horizon)) {
                    continue;
                }
                $nights[] = [
                    'date' => $date->toDateString(),
                    'free_beds' => (int) ($day['freeBeds'] ?? 0),
                    'total_beds' => isset($day['totalSleepingPlaces']) ? (int) $day['totalSleepingPlaces'] : null,
                    'hut_status' => $day['hutStatus'] ?? null,
                    'percentage' => $day['percentage'] ?? null,
                ];
            }
            $written += $this->writeAvailability($hut->id, $nights);
        }

        return $written;
    }

    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** Stable int PK for book-direct huts (the directory id is a GUID). */
    private function hashId(string $guid): int
    {
        return 2_000_000_000 + (crc32($guid) % 1_000_000_000);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetch(): array
    {
        $disk = Storage::disk('local');
        if ($disk->exists(self::CACHE) && (time() - $disk->lastModified(self::CACHE)) < self::CACHE_TTL) {
            return json_decode((string) $disk->get(self::CACHE), true)['features'] ?? [];
        }

        try {
            $response = Http::timeout(60)
                ->retry(2, 1500, throw: false)
                ->withHeaders(['User-Agent' => 'alpine-hut-finder/1.0 (huts.aydinhassan.co.uk)'])
                ->get(self::SERVICE, [
                    'where' => '1=1',
                    'outFields' => 'id,ohrs_hut_id,name,verein_name,meereshoehe,telefon,email,homepage',
                    'returnGeometry' => 'true',
                    'outSR' => '4326',
                    'f' => 'json',
                ]);
        } catch (\Throwable) {
            return [];
        }

        if (! $response->successful() || ! is_array($response->json()) || empty($response->json()['features'])) {
            return [];
        }

        $disk->put(self::CACHE, $response->body());

        return $response->json()['features'];
    }
}
