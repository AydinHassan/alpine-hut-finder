<?php

namespace App\Services;

use App\Sources\AlpenvereinSource;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Fetches per-day bed availability from the (undocumented, public) Alpenverein /
 * SAC Hut Reservation Service at hut-reservation.org. Hut metadata + the id
 * mapping come from the Alpenverein directory ({@see AlpenvereinSource});
 * this only handles the availability endpoint.
 *
 * Called server-side only (no CORS upstream), responses cached to
 * storage/app/hrs-cache and each live call throttled — gentle on the API.
 */
class HutReservationService
{
    private const BASE = 'https://www.hut-reservation.org/api/v1/reservation';

    private const CACHE_DIR = 'hrs-cache';

    /** Pause after each *live* upstream call (µs) to stay gentle on the API. */
    private const REQUEST_DELAY_US = 150000; // 150ms

    /** Availability cache TTL in seconds; 0 disables (always fetch). */
    public int $availabilityTtl = 1800; // 30 min

    private function client(): PendingRequest
    {
        return Http::baseUrl(self::BASE)
            ->timeout(20)
            ->connectTimeout(10)
            ->retry(2, 500)
            ->withHeaders([
                'User-Agent' => 'alpine-hut-finder/1.0 (huts.aydinhassan.co.uk)',
                'Accept' => 'application/json',
            ]);
    }

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
        } finally {
            usleep(self::REQUEST_DELAY_US);
        }

        if (! $response->successful() || ! is_array($response->json())) {
            return [];
        }

        $data = $response->json();
        $this->cachePut("availability-{$hutId}", $data);

        return $data;
    }
}
