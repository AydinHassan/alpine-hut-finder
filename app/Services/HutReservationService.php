<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Read-only client for the (undocumented, public) Alpenverein / SAC Hut
 * Reservation Service JSON API at hut-reservation.org. Only the hut-metadata
 * and availability endpoints are used — neither requires authentication.
 *
 * Called server-side only (the upstream sends no CORS headers) and everything
 * is cached in our own DB; we poll on a schedule rather than on page views.
 */
class HutReservationService
{
    private const BASE = 'https://www.hut-reservation.org/api/v1/reservation';

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
     * Raw hut metadata for a single hutId, or null if it doesn't exist / errors.
     *
     * @return array<string, mixed>|null
     */
    public function hutInfo(int $hutId): ?array
    {
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

        return isset($data['hutId']) ? $data : null;
    }

    /**
     * Per-day availability rows for a hut (array; empty on error).
     *
     * @return array<int, array<string, mixed>>
     */
    public function availability(int $hutId): array
    {
        try {
            $response = $this->client()->get('/getHutAvailability', ['hutId' => $hutId]);
        } catch (\Throwable) {
            return [];
        }

        if (! $response->successful() || ! is_array($response->json())) {
            return [];
        }

        return $response->json();
    }

    /**
     * Normalise a raw hutInfo payload into columns for the `huts` table.
     *
     * @param  array<string, mixed>  $info
     * @return array<string, mixed>
     */
    public function normaliseHut(array $info): array
    {
        [$lat, $lon] = $this->parseCoordinates($info['coordinates'] ?? null);

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
     * "46.3036, 7.4617" => [46.3036, 7.4617]; unparseable => [null, null].
     *
     * @return array{0: float|null, 1: float|null}
     */
    private function parseCoordinates(?string $raw): array
    {
        if (! $raw) {
            return [null, null];
        }

        $parts = array_map('trim', explode(',', $raw));

        if (count($parts) !== 2 || ! is_numeric($parts[0]) || ! is_numeric($parts[1])) {
            return [null, null];
        }

        return [(float) $parts[0], (float) $parts[1]];
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
