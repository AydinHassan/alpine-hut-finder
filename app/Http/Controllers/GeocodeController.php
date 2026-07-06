<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Thin cached proxy for OpenStreetMap's Nominatim geocoder. Runs server-side so
 * we can send a proper identifying User-Agent, cache aggressively (place
 * coordinates don't move), and stay within Nominatim's usage policy instead of
 * hammering it from every browser keystroke.
 */
class GeocodeController extends Controller
{
    private const BASE = 'https://nominatim.openstreetmap.org';

    // Bias results to Austria and its Alpine neighbours (huts near a border
    // still make sense as an origin).
    private const COUNTRIES = 'at,de,it,ch';

    private function client()
    {
        return Http::baseUrl(self::BASE)
            ->timeout(10)
            ->withHeaders([
                'User-Agent' => 'alpine-hut-finder/1.0 (huts.aydinhassan.co.uk)',
                'Accept' => 'application/json',
            ]);
    }

    /** Forward geocode a place name → a short list of {name, lat, lng}. */
    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }

        $results = Cache::remember('geocode:'.mb_strtolower($q), now()->addDays(30), function () use ($q) {
            $response = $this->client()->get('/search', [
                'q' => $q,
                'format' => 'jsonv2',
                'countrycodes' => self::COUNTRIES,
                'limit' => 6,
                'addressdetails' => 1,
            ]);

            if (! $response->successful() || ! is_array($response->json())) {
                return [];
            }

            // Nominatim often returns several OSM objects for one place (a node
            // plus its administrative boundary, say) that reduce to the same
            // label — dedupe by the name we display, keeping the highest-ranked.
            return collect($response->json())->map(fn ($p) => [
                'name' => $this->shortName($p),
                'lat' => (float) $p['lat'],
                'lng' => (float) $p['lon'],
            ])->unique('name')->values()->all();
        });

        return response()->json($results);
    }

    /** Reverse geocode lat/lng → a display name for the user's location. */
    public function reverse(Request $request): JsonResponse
    {
        $lat = (float) $request->query('lat');
        $lng = (float) $request->query('lng');
        if ($lat === 0.0 && $lng === 0.0) {
            return response()->json(['name' => null]);
        }

        $key = 'geocode:rev:'.round($lat, 3).','.round($lng, 3);
        $name = Cache::remember($key, now()->addDays(30), function () use ($lat, $lng) {
            $response = $this->client()->get('/reverse', [
                'lat' => $lat,
                'lon' => $lng,
                'format' => 'jsonv2',
                'zoom' => 12,
            ]);

            if (! $response->successful() || ! is_array($response->json())) {
                return null;
            }

            return $this->shortName($response->json());
        });

        return response()->json(['name' => $name]);
    }

    /**
     * Build a compact "Town, Region" label from a Nominatim result.
     *
     * @param  array<string, mixed>  $p
     */
    private function shortName(array $p): string
    {
        $a = $p['address'] ?? [];
        $place = $a['city'] ?? $a['town'] ?? $a['village'] ?? $a['hamlet']
            ?? $a['municipality'] ?? $a['county'] ?? $p['name'] ?? null;
        $region = $a['state'] ?? $a['country'] ?? null;

        if ($place && $region && $place !== $region) {
            return "{$place}, {$region}";
        }

        return $place ?? $region ?? (string) ($p['display_name'] ?? 'Selected location');
    }
}
