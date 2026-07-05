<?php

namespace App\Support;

/**
 * The Hut Reservation Service stores hut coordinates as free text, and the data
 * is a genuine mess — at least five formats appear across the ~670 huts:
 *
 *   "46.3036, 7.4617"                              decimal degrees, comma
 *   "47.104134/ 9.787181"                          decimal degrees, slash
 *   "5280260 / 445766"                             UTM 33N, "northing / easting"
 *   "UTM Y (Nord) 5.236.808 / UTM X (Ost) 655.466" UTM with labels + thousands dots
 *   "47° 12,3´ / 13° 27,9"                          degrees + decimal minutes
 *   "XXX.XXX / YYY.YYY"                             placeholder — no data
 *
 * parse() normalises all of them to [lat, lon] in WGS84 degrees, or null when
 * the value is a placeholder / unrecoverable. This single fix roughly doubled
 * the number of Austrian huts we could place on the map.
 */
class CoordinateParser
{
    /**
     * @return array{0: float, 1: float}|null  [lat, lon] or null
     */
    public static function parse(?string $raw): ?array
    {
        if ($raw === null) {
            return null;
        }

        $c = trim($raw);
        if ($c === '' || stripos($c, 'XXX') !== false || stripos($c, 'YYY') !== false) {
            return null;
        }

        // Degrees + decimal minutes, e.g. "47° 12,3´ / 13° 27,9"
        if (str_contains($c, '°')) {
            $vals = [];
            foreach (explode('/', $c) as $part) {
                if (preg_match('/(\d+)\s*°\s*([\d.,]+)/', $part, $m)) {
                    $vals[] = (int) $m[1] + (float) str_replace(',', '.', $m[2]) / 60;
                }
            }
            if (count($vals) === 2) {
                [$a, $b] = $vals;
                return self::validate($a >= $b ? $a : $b, $a >= $b ? $b : $a);
            }

            return null;
        }

        $sep = str_contains($c, '/') ? '/' : (str_contains($c, ',') ? ',' : null);
        if ($sep === null) {
            return null;
        }

        $parts = array_map('trim', explode($sep, $c));
        if (count($parts) !== 2) {
            return null;
        }

        // Plain decimal degrees (either separator).
        if (is_numeric($parts[0]) && is_numeric($parts[1])) {
            $a = (float) $parts[0];
            $b = (float) $parts[1];
            if (abs($a) < 180 && abs($b) < 180) {
                // Latitude is the ~47 value in Austria; swap if reversed.
                return ($a >= 46 && $a <= 49.2) ? self::validate($a, $b) : self::validate($b, $a);
            }
        }

        // UTM "northing / easting" (with optional labels and thousands dots).
        $n = self::extractInt($parts[0]);
        $e = self::extractInt($parts[1]);
        if ($n === null || $e === null) {
            return null;
        }
        if ($n < $e) {
            [$n, $e] = [$e, $n]; // northing (~5.2M) is the larger
        }
        // Austria spans UTM zones 32 and 33; try both, keep the one that lands
        // inside the plausible range.
        foreach ([33, 32] as $zone) {
            [$lat, $lon] = self::utmToLatLon($e, $n, $zone);
            if ($v = self::validate($lat, $lon)) {
                return $v;
            }
        }

        return null;
    }

    private static function extractInt(string $part): ?int
    {
        if (preg_match('/(\d[\d.]*\d)/', str_replace(' ', '', $part), $m)) {
            return (int) str_replace('.', '', $m[1]); // drop thousands separators
        }

        return null;
    }

    /**
     * @return array{0: float, 1: float}|null
     */
    private static function validate(float $lat, float $lon): ?array
    {
        // Loose Alpine bounding box; the precise Austria test is done separately
        // against the country polygon.
        if ($lat >= 46 && $lat <= 49.2 && $lon >= 9 && $lon <= 17.5) {
            return [round($lat, 6), round($lon, 6)];
        }

        return null;
    }

    /**
     * UTM (WGS84) inverse — Snyder/USGS series, accurate to well under a metre.
     *
     * @return array{0: float, 1: float}  [lat, lon] in degrees
     */
    private static function utmToLatLon(float $easting, float $northing, int $zone): array
    {
        $k0 = 0.9996;
        $a = 6378137.0;
        $f = 1 / 298.257223563;
        $e2 = $f * (2 - $f);
        $ep2 = $e2 / (1 - $e2);
        $x = $easting - 500000.0;
        $y = $northing;

        $m = $y / $k0;
        $mu = $m / ($a * (1 - $e2 / 4 - 3 * $e2 ** 2 / 64 - 5 * $e2 ** 3 / 256));
        $e1 = (1 - sqrt(1 - $e2)) / (1 + sqrt(1 - $e2));
        $phi1 = $mu
            + (3 * $e1 / 2 - 27 * $e1 ** 3 / 32) * sin(2 * $mu)
            + (21 * $e1 ** 2 / 16 - 55 * $e1 ** 4 / 32) * sin(4 * $mu)
            + (151 * $e1 ** 3 / 96) * sin(6 * $mu);
        $n1 = $a / sqrt(1 - $e2 * sin($phi1) ** 2);
        $t1 = tan($phi1) ** 2;
        $c1 = $ep2 * cos($phi1) ** 2;
        $r1 = $a * (1 - $e2) / pow(1 - $e2 * sin($phi1) ** 2, 1.5);
        $d = $x / ($n1 * $k0);

        $lat = $phi1 - ($n1 * tan($phi1) / $r1) * (
            $d ** 2 / 2
            - (5 + 3 * $t1 + 10 * $c1 - 4 * $c1 ** 2 - 9 * $ep2) * $d ** 4 / 24
            + (61 + 90 * $t1 + 298 * $c1 + 45 * $t1 ** 2 - 252 * $ep2 - 3 * $c1 ** 2) * $d ** 6 / 720
        );
        $lon = ($d
            - (1 + 2 * $t1 + $c1) * $d ** 3 / 6
            + (5 - 2 * $c1 + 28 * $t1 - 3 * $c1 ** 2 + 8 * $ep2 + 24 * $t1 ** 2) * $d ** 5 / 120
        ) / cos($phi1);
        $lon0 = deg2rad(6 * $zone - 183);

        return [rad2deg($lat), rad2deg($lon0) + rad2deg($lon)];
    }
}
