<?php

namespace App\Services;

use App\Support\CoordinateParser;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Read-only client for the (undocumented, public) Alpenverein / SAC Hut
 * Reservation Service JSON API at hut-reservation.org. Only the hut-metadata
 * and availability endpoints are used — neither requires authentication.
 *
 * Called server-side only (the upstream sends no CORS headers) and everything
 * is cached in our own DB; we poll on a schedule rather than on page views.
 *
 * Every raw response is also written to an on-disk snapshot cache
 * (storage/app/hrs-cache) so repeated runs — development, re-syncs, tweaking the
 * catalog filter — reuse saved data instead of re-hitting the upstream. hutInfo
 * (the catalog) rarely changes so it is cached for a month; availability uses a
 * short TTL so scheduled syncs still see fresh free-bed counts.
 */
class HutReservationService
{
    private const BASE = 'https://www.hut-reservation.org/api/v1/reservation';

    private const CACHE_DIR = 'hrs-cache';

    private const HUTINFO_TTL = 2592000; // 30 days

    /** Availability cache TTL in seconds; 0 disables (always fetch). */
    public int $availabilityTtl = 1800; // 30 min

    private function client(): PendingRequest
    {
        return Http::baseUrl(self::BASE)
            ->timeout(20)
            ->connectTimeout(10)
            ->retry(2, 500)
            ->withHeaders([
                'User-Agent' => 'alpine-hut-finder/1.0 (personal project)',
                'Accept' => 'application/json',
            ]);
    }

    /**
     * Read a cached JSON snapshot if it is younger than $ttl seconds.
     */
    private function cacheGet(string $key, int $ttl): mixed
    {
        if ($ttl <= 0) {
            return null;
        }

        $path = self::CACHE_DIR."/{$key}.json";
        $disk = Storage::disk('local');
        if (! $disk->exists($path) || (time() - $disk->lastModified($path)) > $ttl) {
            return null;
        }

        return json_decode((string) $disk->get($path), true);
    }

    private function cachePut(string $key, mixed $value): void
    {
        Storage::disk('local')->put(self::CACHE_DIR."/{$key}.json", json_encode($value));
    }

    /**
     * Raw hut metadata for a single hutId, or null if it doesn't exist / errors.
     *
     * @return array<string, mixed>|null
     */
    public function hutInfo(int $hutId): ?array
    {
        if (($cached = $this->cacheGet("hutInfo-{$hutId}", self::HUTINFO_TTL)) !== null) {
            return $cached ?: null;
        }

        try {
            $response = $this->client()->get("/hutInfo/{$hutId}");
        } catch (\Throwable) {
            // Transient network failure after retries — skip this id rather
            // than crashing a 700-hut enumeration.
            return null;
        }

        if (! $response->successful() || ! is_array($response->json())) {
            return null;
        }

        $data = $response->json();
        $data = isset($data['hutId']) ? $data : null;
        $this->cachePut("hutInfo-{$hutId}", $data ?: false);

        return $data;
    }

    /**
     * Per-day availability rows for a hut (array; empty on error).
     *
     * @return array<int, array<string, mixed>>
     */
    public function availability(int $hutId): array
    {
        if (($cached = $this->cacheGet("availability-{$hutId}", $this->availabilityTtl)) !== null) {
            return $cached;
        }

        try {
            $response = $this->client()->get('/getHutAvailability', ['hutId' => $hutId]);
        } catch (\Throwable) {
            return [];
        }

        if (! $response->successful() || ! is_array($response->json())) {
            return [];
        }

        $data = $response->json();
        $this->cachePut("availability-{$hutId}", $data);

        return $data;
    }

    /**
     * Normalise a raw hutInfo payload into columns for the `huts` table.
     *
     * @param  array<string, mixed>  $info
     * @return array<string, mixed>
     */
    public function normaliseHut(array $info): array
    {
        [$lat, $lon] = CoordinateParser::parse($info['coordinates'] ?? null) ?? [null, null];

        return [
            'id' => (int) $info['hutId'],
            'name' => trim((string) ($info['hutName'] ?? 'Unknown hut')),
            'country' => $info['tenantCountry'] ?? null,
            'club' => $info['tenantCode'] ?? null,
            'latitude' => $lat,
            'longitude' => $lon,
            'altitude' => $this->parseInt($info['altitude'] ?? null),
            'total_beds' => $this->parseInt($info['totalBedsInfo'] ?? null),
            'phone' => $info['phone'] ?? null,
            'website' => $info['hutWebsite'] ?? null,
        ];
    }

    /**
     * "2840 m" => 2840; "1.922 m" (German thousands separator) => 1922;
     * "100" => 100. Strip separators and unit before reading the digit run.
     */
    private function parseInt(mixed $raw): ?int
    {
        if ($raw === null) {
            return null;
        }

        $clean = preg_replace('/[.,\s]/', '', (string) $raw);

        if ($clean !== null && preg_match('/\d+/', $clean, $m)) {
            return (int) $m[0];
        }

        return null;
    }
}
