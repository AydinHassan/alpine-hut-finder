<?php

namespace App\Console\Commands;

use App\Models\Hut;
use App\Services\HutReservationService;
use App\Support\AustriaBoundary;
use Illuminate\Console\Command;

class SyncHutCatalog extends Command
{
    protected $signature = 'huts:sync-catalog
        {--from=1 : First hutId to probe}
        {--max=720 : Last hutId to probe}
        {--ids= : Comma-separated hutIds to fetch instead of the full range}
        {--sleep=120 : Milliseconds to wait between requests}';

    protected $description = 'Enumerate the Hut Reservation Service and store the huts physically in Austria.';

    public function handle(HutReservationService $service): int
    {
        $sleep = (int) $this->option('sleep') * 1000;

        // Cached responses (storage/app/hrs-cache) make repeated runs free; the
        // --ids option lets a re-sync target a known set instead of re-probing
        // the whole id space.
        if ($this->option('ids')) {
            $ids = array_map('intval', array_filter(explode(',', $this->option('ids'))));
        } else {
            $ids = range((int) $this->option('from'), (int) $this->option('max'));
        }

        // Keep a hut only if its coordinates fall inside Austria's border. This
        // is the honest "in Austria" test: the API reports the operating club's
        // country, not the hut's location, so a hut run by the German club can
        // be in Tyrol (keep) or Bavaria (drop) — only the border tells them apart.
        $austria = new AustriaBoundary;

        $kept = 0;
        $seen = 0;
        $noCoords = 0;

        $this->info('Probing '.count($ids).' hutIds, keeping huts inside Austria...');
        $bar = $this->output->createProgressBar(count($ids));
        $bar->start();

        foreach ($ids as $id) {
            $bar->advance();

            $info = $service->hutInfo($id);
            if ($info !== null) {
                $seen++;
                $hut = $service->normaliseHut($info);

                if ($hut['latitude'] === null || $hut['longitude'] === null) {
                    $noCoords++;
                } elseif ($austria->contains($hut['latitude'], $hut['longitude'])) {
                    Hut::updateOrCreate(
                        ['id' => $hut['id']],
                        $hut + [
                            'source' => 'hrs',
                            'booking_url' => "https://www.hut-reservation.org/reservation/book-hut/{$hut['id']}/wizard",
                            'catalog_synced_at' => now(),
                        ],
                    );
                    $kept++;
                }
            }

            if ($sleep > 0) {
                usleep($sleep);
            }
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Done. Saw {$seen} huts, stored {$kept} in Austria ({$noCoords} skipped for missing coordinates).");

        return self::SUCCESS;
    }
}
