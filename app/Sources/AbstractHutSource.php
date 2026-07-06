<?php

namespace App\Sources;

use App\Models\Hut;
use App\Support\AustriaBoundary;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Shared machinery for every {@see HutSource}: the Austria border test, hut
 * upserts, and availability writes (including the free-beds clamp — some
 * platforms report negative counts for overbooked huts). Concrete sources only
 * implement the platform-specific fetching.
 */
abstract class AbstractHutSource implements HutSource
{
    private ?AustriaBoundary $austria = null;

    /** Most sources have online availability; metadata-only ones override this. */
    public function providesAvailability(): bool
    {
        return true;
    }

    protected function inAustria(?float $lat, ?float $lon): bool
    {
        if ($lat === null || $lon === null) {
            return false;
        }
        $this->austria ??= new AustriaBoundary;

        return $this->austria->contains($lat, $lon);
    }

    /**
     * Upsert one hut, stamping the source and catalog time.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function upsertHut(int $id, array $attributes): void
    {
        Hut::updateOrCreate(
            ['id' => $id],
            $attributes + [
                'source' => $this->key(),
                'bookable_online' => $this->providesAvailability(),
                'catalog_synced_at' => now(),
            ],
        );
    }

    /**
     * Write a hut's nightly availability. `$nights` is a list of
     * ['date' => 'Y-m-d', 'free_beds' => int, 'total_beds' => ?int,
     *  'hut_status' => ?string, 'percentage' => ?string].
     * free_beds is clamped to >= 0 (overbooked huts report negatives).
     *
     * @param  array<int, array<string, mixed>>  $nights
     * @return int rows written
     */
    protected function writeAvailability(int $hutId, array $nights): int
    {
        if ($nights === []) {
            return 0;
        }

        $now = now();
        $rows = array_map(fn (array $n) => [
            'hut_id' => $hutId,
            'date' => $n['date'],
            'free_beds' => max(0, (int) ($n['free_beds'] ?? 0)),
            'total_beds' => $n['total_beds'] ?? null,
            'hut_status' => $n['hut_status'] ?? null,
            'percentage' => $n['percentage'] ?? null,
            'fetched_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ], $nights);

        DB::table('hut_availabilities')->upsert(
            $rows,
            ['hut_id', 'date'],
            ['free_beds', 'total_beds', 'hut_status', 'percentage', 'fetched_at', 'updated_at'],
        );

        return count($rows);
    }

    /** The stored huts belonging to this source. */
    protected function huts()
    {
        return Hut::query()->where('source', $this->key())->orderBy('id')->get();
    }

    /** Inclusive [today, today+days] window as immutable dates. */
    protected function window(int $days): array
    {
        $today = CarbonImmutable::today();

        return [$today, $today->addDays($days)];
    }

    /** Drop availability rows now in the past, across all sources. */
    public static function prunePast(): void
    {
        DB::table('hut_availabilities')
            ->where('date', '<', CarbonImmutable::today()->toDateString())
            ->delete();
    }
}
