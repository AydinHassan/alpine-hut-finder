<?php

namespace App\Sources;

use App\Models\Hut;
use App\Services\HuettenHolidayService;
use Carbon\CarbonImmutable;

/**
 * huetten-holiday.com — ÖTK huts, private Schutzhütten, and Alpenverein sections
 * that book independently of the central system (e.g. Fischerhütte on the
 * Schneeberg). Cabin ids are offset by {@see Hut::HUETTEN_HOLIDAY_ID_OFFSET} so
 * they never collide with HRS hut ids.
 */
class HuettenHolidaySource extends AbstractHutSource
{
    public function __construct(
        private readonly HuettenHolidayService $api,
    ) {}

    public function key(): string
    {
        return 'huetten-holiday';
    }

    public function label(): string
    {
        return 'huetten-holiday.com';
    }

    public function syncCatalog(): int
    {
        $kept = 0;

        foreach ($this->api->cabins() as $cabin) {
            $lat = is_numeric($cabin['latitude'] ?? null) ? (float) $cabin['latitude'] : null;
            $lon = is_numeric($cabin['longitude'] ?? null) ? (float) $cabin['longitude'] : null;
            if (! $this->inAustria($lat, $lon)) {
                continue;
            }

            $this->upsertHut(Hut::HUETTEN_HOLIDAY_ID_OFFSET + (int) $cabin['id'], [
                'name' => trim((string) $cabin['name']),
                'country' => $cabin['country']['code'] ?? 'AT',
                'club' => ($cabin['is_private'] ?? false) ? 'Private' : null,
                'latitude' => $lat,
                'longitude' => $lon,
                'altitude' => is_numeric($cabin['altitude'] ?? null) ? (int) $cabin['altitude'] : null,
                'website' => $cabin['website'] ?? null,
                'booking_url' => 'https://www.huetten-holiday.com/huts/'.($cabin['slug'] ?? $cabin['id']),
            ]);
            $kept++;
        }

        return $kept;
    }

    public function syncAvailability(int $days): int
    {
        [$today, $horizon] = $this->window($days);
        $months = $this->monthsSpanning($today, $horizon);
        $written = 0;

        foreach ($this->huts() as $hut) {
            $cabinId = $hut->id - Hut::HUETTEN_HOLIDAY_ID_OFFSET;
            $nights = [];
            foreach ($this->api->availability($cabinId, $months) as $date => $info) {
                $d = CarbonImmutable::parse($date);
                if ($d->lt($today) || $d->gt($horizon)) {
                    continue;
                }
                $nights[] = [
                    'date' => $d->toDateString(),
                    'free_beds' => $info['freeBeds'],   // clamped in writeAvailability
                    'total_beds' => $info['totalBeds'],
                ];
            }
            $written += $this->writeAvailability($hut->id, $nights);
        }

        return $written;
    }

    /**
     * The [month, year] pairs the window touches (the HH API is queried a month
     * at a time).
     *
     * @return array<int, array{0:int,1:int}>
     */
    private function monthsSpanning(CarbonImmutable $today, CarbonImmutable $horizon): array
    {
        $months = [];
        for ($m = $today->startOfMonth(); $m->lte($horizon); $m = $m->addMonthNoOverflow()) {
            $months[] = [(int) $m->format('n'), (int) $m->format('Y')];
        }

        return $months;
    }
}
