<?php

namespace App\Sources;

use App\Models\Hut;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * OpenStreetMap (via the Overpass API) — every named Austrian alpine hut, used
 * to surface the huts you can only book directly (phone/email/website), which
 * are NOT in any queryable availability system. These have no availability; the
 * front end shows them behind an opt-in toggle, clearly marked "book direct".
 *
 * Deduped against the live-availability huts by proximity so the same physical
 * hut never appears twice.
 */
class OsmSource extends AbstractHutSource
{
    private const CACHE = 'osm-cache/overpass.json';

    private const CACHE_TTL = 2592000; // 30 days — OSM hut metadata rarely changes

    private const DEDUPE_METRES = 250;

    // The main Overpass endpoint often 504s under load; fall through to mirrors.
    private const ENDPOINTS = [
        'https://overpass-api.de/api/interpreter',
        'https://overpass.kumi.systems/api/interpreter',
        'https://overpass.private.coffee/api/interpreter',
    ];

    private const QUERY = <<<'OVERPASS'
        [out:json][timeout:120];
        area["name"="Österreich"]["admin_level"="2"]->.at;
        nwr["tourism"="alpine_hut"]["name"](area.at);
        out tags center;
        OVERPASS;

    public function key(): string
    {
        return 'osm';
    }

    public function label(): string
    {
        return 'OpenStreetMap (book direct)';
    }

    public function providesAvailability(): bool
    {
        return false;
    }

    /** OSM huts have no queryable availability. */
    public function syncAvailability(int $days): int
    {
        return 0;
    }

    public function syncCatalog(): int
    {
        $elements = $this->fetch();
        if ($elements === []) {
            return 0;
        }

        // Points of the live-availability huts, to dedupe against — a book-direct
        // hut that is really one of our bookable huts must not appear twice.
        $seen = Hut::query()
            ->where('source', '!=', $this->key())
            ->whereNotNull('latitude')
            ->get(['latitude', 'longitude'])
            ->map(fn ($h) => [(float) $h->latitude, (float) $h->longitude])
            ->all();

        $kept = 0;
        foreach ($elements as $e) {
            $lat = $e['lat'] ?? $e['center']['lat'] ?? null;
            $lon = $e['lon'] ?? $e['center']['lon'] ?? null;
            if ($lat === null || $lon === null) {
                continue;
            }
            $lat = (float) $lat;
            $lon = (float) $lon;

            $tags = $e['tags'] ?? [];
            $phone = $tags['phone'] ?? $tags['contact:phone'] ?? null;
            $website = $tags['website'] ?? $tags['contact:website'] ?? $tags['url'] ?? null;
            $email = $tags['email'] ?? $tags['contact:email'] ?? null;

            // Only keep huts you can actually reach to book.
            if (! $phone && ! $website && ! $email) {
                continue;
            }

            // Skip if it coincides with an already-known hut (live or OSM).
            if ($this->near($lat, $lon, $seen)) {
                continue;
            }

            $this->upsertHut($this->osmId((string) $e['type'], $e['id']), [
                'name' => trim((string) ($tags['name'] ?? 'Hut')),
                'country' => 'AT',
                'club' => $tags['operator'] ?? null,
                'latitude' => $lat,
                'longitude' => $lon,
                'altitude' => isset($tags['ele']) && is_numeric($tags['ele']) ? (int) $tags['ele'] : null,
                'phone' => $phone,
                'email' => $email,
                'website' => $website,
                'booking_url' => null,
            ]);
            $seen[] = [$lat, $lon];
            $kept++;
        }

        return $kept;
    }

    /**
     * @param  array<int, array{0: float, 1: float}>  $points
     */
    private function near(float $lat, float $lon, array $points): bool
    {
        foreach ($points as [$plat, $plon]) {
            if ($this->metres($lat, $lon, $plat, $plon) < self::DEDUPE_METRES) {
                return true;
            }
        }

        return false;
    }

    private function metres(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r = 6371000;
        $rad = fn (float $x) => $x * M_PI / 180;
        $dLat = $rad($lat2 - $lat1);
        $dLon = $rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos($rad($lat1)) * cos($rad($lat2)) * sin($dLon / 2) ** 2;

        return 2 * $r * asin(sqrt($a));
    }

    /**
     * A stable id in the 2e9–3e9 band: raw OSM ids overflow the unsigned-int PK,
     * and the low bands are used by hrs (1–720) and huetten-holiday (1e6+).
     */
    private function osmId(string $type, int|string $id): int
    {
        return 2_000_000_000 + (crc32("{$type}/{$id}") % 1_000_000_000);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetch(): array
    {
        $disk = Storage::disk('local');
        if ($disk->exists(self::CACHE) && (time() - $disk->lastModified(self::CACHE)) < self::CACHE_TTL) {
            return json_decode((string) $disk->get(self::CACHE), true)['elements'] ?? [];
        }

        foreach (self::ENDPOINTS as $url) {
            try {
                $response = Http::asForm()
                    ->timeout(180)
                    ->retry(2, 2000, throw: false)
                    ->withHeaders(['User-Agent' => 'alpine-hut-finder/1.0 (huts.aydinhassan.co.uk)'])
                    ->post($url, ['data' => self::QUERY]);
            } catch (\Throwable) {
                continue;
            }

            if ($response->successful() && is_array($response->json()) && ! empty($response->json()['elements'])) {
                $disk->put(self::CACHE, $response->body());

                return $response->json()['elements'];
            }
        }

        return [];
    }
}
