<?php

namespace App\Console\Commands;

use App\Models\Hut;
use App\Services\HutReservationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncHutAvailability extends Command
{
    protected $signature = 'huts:sync-availability
        {--days=21 : How many days ahead to keep}
        {--sleep=150 : Milliseconds to wait between requests}';

    protected $description = 'Refresh cached bed availability for every stored hut.';

    public function handle(HutReservationService $service): int
    {
        $days = (int) $this->option('days');
        $sleep = (int) $this->option('sleep') * 1000;
        $today = CarbonImmutable::today();
        $horizon = $today->addDays($days);

        $huts = Hut::query()->orderBy('id')->get();
        if ($huts->isEmpty()) {
            $this->warn('No huts stored yet. Run huts:sync-catalog first.');

            return self::SUCCESS;
        }

        $this->info("Refreshing availability for {$huts->count()} huts ({$days} days)...");
        $bar = $this->output->createProgressBar($huts->count());
        $bar->start();

        $now = now();
        $rowsWritten = 0;

        foreach ($huts as $hut) {
            $bar->advance();
            $rows = [];

            foreach ($service->availability($hut->id) as $day) {
                $date = isset($day['date']) ? CarbonImmutable::parse($day['date']) : null;
                if ($date === null || $date->lt($today) || $date->gt($horizon)) {
                    continue;
                }

                $rows[] = [
                    'hut_id' => $hut->id,
                    'date' => $date->toDateString(),
                    'free_beds' => (int) ($day['freeBeds'] ?? 0),
                    'total_beds' => isset($day['totalSleepingPlaces']) ? (int) $day['totalSleepingPlaces'] : null,
                    'hut_status' => $day['hutStatus'] ?? null,
                    'percentage' => $day['percentage'] ?? null,
                    'fetched_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($rows !== []) {
                DB::table('hut_availabilities')->upsert(
                    $rows,
                    ['hut_id', 'date'],
                    ['free_beds', 'total_beds', 'hut_status', 'percentage', 'fetched_at', 'updated_at'],
                );
                $rowsWritten += count($rows);
            }

            if ($sleep > 0) {
                usleep($sleep);
            }
        }

        // Drop past dates so the cache stays small.
        DB::table('hut_availabilities')->where('date', '<', $today->toDateString())->delete();

        $bar->finish();
        $this->newLine(2);
        $this->info("Done. Wrote {$rowsWritten} availability rows.");

        return self::SUCCESS;
    }
}
