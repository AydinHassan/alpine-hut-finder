<?php

namespace App\Console\Commands;

use App\Models\Hut;
use App\Services\HuettenHolidayService;
use App\Support\AustriaBoundary;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncHuettenHoliday extends Command
{
    protected $signature = 'huts:sync-huetten-holiday {--days=21 : Days of availability to keep}';

    protected $description = 'Import Austrian huts (ÖTK, private, opted-out sections) and availability from huetten-holiday.com.';

    public function handle(HuettenHolidayService $service): int
    {
        $austria = new AustriaBoundary;
        $today = CarbonImmutable::today();
        $horizon = $today->addDays((int) $this->option('days'));

        $cabins = collect($service->cabins())
            ->filter(fn ($c) => is_numeric($c['latitude'] ?? null) && is_numeric($c['longitude'] ?? null))
            ->filter(fn ($c) => $austria->contains((float) $c['latitude'], (float) $c['longitude']))
            ->values();

        $this->info("huetten-holiday: {$cabins->count()} cabins in Austria. Storing + syncing availability...");
        $bar = $this->output->createProgressBar($cabins->count());
        $bar->start();

        // Cover today's month plus the next two so the whole horizon is fetched.
        $months = [];
        for ($m = $today; $m->lte($horizon); $m = $m->addMonthNoOverflow()->startOfMonth()) {
            $months[] = [(int) $m->format('n'), (int) $m->format('Y')];
        }

        $now = now();
        $rowsWritten = 0;

        foreach ($cabins as $cabin) {
            $bar->advance();

            $hutId = Hut::HUETTEN_HOLIDAY_ID_OFFSET + (int) $cabin['id'];
            Hut::updateOrCreate(['id' => $hutId], [
                'source' => 'huetten-holiday',
                'name' => trim((string) $cabin['name']),
                'country' => $cabin['country']['code'] ?? 'AT',
                'club' => $cabin['is_private'] ?? false ? 'Private' : null,
                'latitude' => (float) $cabin['latitude'],
                'longitude' => (float) $cabin['longitude'],
                'altitude' => is_numeric($cabin['altitude'] ?? null) ? (int) $cabin['altitude'] : null,
                'website' => $cabin['website'] ?? null,
                'booking_url' => 'https://www.huetten-holiday.com/huts/'.($cabin['slug'] ?? $cabin['id']),
                'catalog_synced_at' => $now,
            ]);

            $rows = [];
            foreach ($service->availability((int) $cabin['id'], $months) as $date => $info) {
                $d = CarbonImmutable::parse($date);
                if ($d->lt($today) || $d->gt($horizon)) {
                    continue;
                }
                $rows[] = [
                    'hut_id' => $hutId,
                    'date' => $d->toDateString(),
                    'free_beds' => $info['freeBeds'],
                    'total_beds' => $info['totalBeds'],
                    'hut_status' => null,
                    'percentage' => null,
                    'fetched_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($rows !== []) {
                DB::table('hut_availabilities')->upsert($rows, ['hut_id', 'date'],
                    ['free_beds', 'total_beds', 'fetched_at', 'updated_at']);
                $rowsWritten += count($rows);
            }
        }

        DB::table('hut_availabilities')->where('date', '<', $today->toDateString())->delete();

        $bar->finish();
        $this->newLine(2);
        $this->info("Done. {$cabins->count()} huts, {$rowsWritten} availability rows.");

        return self::SUCCESS;
    }
}
