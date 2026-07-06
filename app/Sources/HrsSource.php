<?php

namespace App\Sources;

use App\Services\HutReservationService;
use Carbon\CarbonImmutable;

/**
 * Alpenverein / SAC Hut Reservation Service (hut-reservation.org) — the shared
 * booking system for the German, Austrian, South-Tyrolean and Swiss clubs.
 * Huts are addressed by a small numeric id space, so the catalogue is built by
 * probing ids and keeping the ones that fall inside Austria.
 */
class HrsSource extends AbstractHutSource
{
    public function __construct(
        private readonly HutReservationService $api,
        private readonly int $maxHutId = 720,
    ) {}

    public function key(): string
    {
        return 'hrs';
    }

    public function label(): string
    {
        return 'Alpenverein / SAC';
    }

    public function syncCatalog(): int
    {
        $kept = 0;

        foreach (range(1, $this->maxHutId) as $id) {
            $info = $this->api->hutInfo($id);
            if ($info === null) {
                continue;
            }

            $hut = $this->api->normaliseHut($info);
            if (! $this->inAustria($hut['latitude'], $hut['longitude'])) {
                continue;
            }

            $this->upsertHut($hut['id'], $hut + [
                'booking_url' => "https://www.hut-reservation.org/reservation/book-hut/{$hut['id']}/wizard",
            ]);
            $kept++;
        }

        return $kept;
    }

    public function syncAvailability(int $days): int
    {
        [$today, $horizon] = $this->window($days);
        $written = 0;

        foreach ($this->huts() as $hut) {
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
}
