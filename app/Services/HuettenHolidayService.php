<?php

namespace App\Services;

use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Client for huetten-holiday.com — a Laravel booking platform hosting Austrian
 * huts that are NOT on the Alpenverein system (ÖTK, private Schutzhütten, and
 * Alpenverein sections that book independently, e.g. Fischerhütte on the
 * Schneeberg). It exposes clean JSON:
 *
 *   POST /get-filtered-cabins            -> paginated cabin list (coords, etc.)
 *   POST /cabins/get-month-availability  -> per-day free beds for one cabin
 *
 * Auth is just Laravel's CSRF cookie: GET a page to receive XSRF-TOKEN, then
 * echo it back as X-XSRF-TOKEN. As with the HRS client, responses are cached to
 * storage/app/hh-cache so re-syncs don't re-hit the site.
 */
class HuettenHolidayService
{
    private const BASE = 'https://www.huetten-holiday.com';

    private const CACHE_DIR = 'hh-cache';

    private const LIST_TTL = 2592000; // 30 days

    public int $availabilityTtl = 1800; // 30 min

    private ?string $xsrf = null;

    private ?CookieJar $jar = null;

    private function client(): PendingRequest
    {
        $this->jar ??= new CookieJar;

        return Http::baseUrl(self::BASE)
            ->timeout(25)
            ->connectTimeout(10)
            ->retry(2, 500)
            ->withOptions(['cookies' => $this->jar]) // persist session + XSRF cookies
            ->withHeaders([
                'User-Agent' => 'alpine-hut-finder/1.0 (personal project)',
                'X-Requested-With' => 'XMLHttpRequest',
                'Accept' => 'application/json',
            ]);
    }

    /**
     * Prime the CSRF cookie once per run (Laravel: GET sets the XSRF-TOKEN and
     * session cookies in the shared jar, then the value is echoed back as a
     * header on writes).
     */
    private function token(): ?string
    {
        if ($this->xsrf !== null) {
            return $this->xsrf;
        }

        $this->client()->get('/huts');
        foreach ($this->jar?->toArray() ?? [] as $cookie) {
            if (($cookie['Name'] ?? null) === 'XSRF-TOKEN') {
                return $this->xsrf = urldecode($cookie['Value']);
            }
        }

        return null;
    }

    private function authed(): PendingRequest
    {
        return $this->client()->withHeaders(['X-XSRF-TOKEN' => (string) $this->token()]);
    }

    private function cacheGet(string $key, int $ttl): mixed
    {
        $path = self::CACHE_DIR."/{$key}.json";
        $disk = Storage::disk('local');
        if ($ttl <= 0 || ! $disk->exists($path) || (time() - $disk->lastModified($path)) > $ttl) {
            return null;
        }

        return json_decode((string) $disk->get($path), true);
    }

    private function cachePut(string $key, mixed $value): void
    {
        Storage::disk('local')->put(self::CACHE_DIR."/{$key}.json", json_encode($value));
    }

    /**
     * All cabins across every page (each cabin has id, name, coordinates,
     * altitude, website, country, email_inquiry, slug).
     *
     * @return array<int, array<string, mixed>>
     */
    public function cabins(): array
    {
        if (($cached = $this->cacheGet('cabins', self::LIST_TTL)) !== null) {
            return $cached;
        }

        $all = [];
        $page = 1;
        do {
            $json = $this->authed()
                ->post('/get-filtered-cabins?page='.$page, ['filters' => (object) []])
                ->json();
            foreach ($json['data'] ?? [] as $cabin) {
                $all[$cabin['id']] = $cabin;
            }
            $last = $json['last_page'] ?? 1;
            $page++;
        } while ($page <= $last);

        $all = array_values($all);
        $this->cachePut('cabins', $all);

        return $all;
    }

    /**
     * Per-day availability for a cabin over the given months, as
     * [ 'YYYY-MM-DD' => ['freeBeds' => int, 'totalBeds' => int] ].
     *
     * @param  array<int, array{0:int,1:int}>  $months  list of [month, year]
     * @return array<string, array{freeBeds:int,totalBeds:int}>
     */
    public function availability(int $cabinId, array $months): array
    {
        $out = [];
        foreach ($months as [$month, $year]) {
            $key = "avail-{$cabinId}-{$year}-{$month}";
            $rows = $this->cacheGet($key, $this->availabilityTtl);
            if ($rows === null) {
                $response = $this->authed()->post('/cabins/get-month-availability', [
                    'cabinId' => $cabinId,
                    'selectedMonth' => ['monthNumber' => $month, 'year' => $year],
                    'multipleCalendar' => false,
                ]);
                $rows = is_array($response->json()) ? $response->json() : [];
                $this->cachePut($key, $rows);
                usleep(150000); // 150ms — gentle on the upstream between live calls
            }

            foreach ($rows as $row) {
                if (empty($row['date'])) {
                    continue;
                }
                $date = substr($row['date'], 0, 10);
                $free = array_sum(array_map(fn ($r) => (int) ($r['places'] ?? 0), $row['rooms'] ?? []));
                $out[$date] = ['freeBeds' => $free, 'totalBeds' => (int) ($row['totalPlaces'] ?? 0)];
            }
        }

        return $out;
    }
}
