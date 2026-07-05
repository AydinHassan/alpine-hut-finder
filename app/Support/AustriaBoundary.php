<?php

namespace App\Support;

/**
 * Point-in-polygon test against Austria's national border (a GeoJSON polygon in
 * resources/data/austria.geojson). Used to keep only huts *physically* in
 * Austria — the reservation API only tells us the operating club's country, not
 * the hut's location, so many huts run by the German club are actually in Tyrol
 * (keep them) while some are in Bavaria (drop them). A border test is the only
 * way to tell them apart.
 */
class AustriaBoundary
{
    /** @var array<int, array<int, array{0: float, 1: float}>> rings of [lon, lat] */
    private array $rings;

    public function __construct(?string $geojsonPath = null)
    {
        $path = $geojsonPath ?? resource_path('data/austria.geojson');
        $geo = json_decode((string) file_get_contents($path), true);

        $this->rings = [];
        $features = $geo['features'] ?? [$geo];
        foreach ($features as $feature) {
            $geometry = $feature['geometry'] ?? $feature;
            $this->collect($geometry);
        }
    }

    private function collect(array $geometry): void
    {
        if (($geometry['type'] ?? null) === 'Polygon') {
            $this->rings[] = $geometry['coordinates'][0];
        } elseif (($geometry['type'] ?? null) === 'MultiPolygon') {
            foreach ($geometry['coordinates'] as $polygon) {
                $this->rings[] = $polygon[0];
            }
        }
    }

    public function contains(float $lat, float $lon): bool
    {
        foreach ($this->rings as $ring) {
            if ($this->inRing($lon, $lat, $ring)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array{0: float, 1: float}>  $ring
     */
    private function inRing(float $lon, float $lat, array $ring): bool
    {
        $inside = false;
        $n = count($ring);
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            [$xi, $yi] = $ring[$i];
            [$xj, $yj] = $ring[$j];
            if (($yi > $lat) !== ($yj > $lat)
                && $lon < ($xj - $xi) * ($lat - $yi) / ($yj - $yi) + $xi) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }
}
