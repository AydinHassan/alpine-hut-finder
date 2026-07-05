<?php

namespace App\Console\Commands;

use App\Models\Hut;
use App\Services\HutReservationService;
use Illuminate\Console\Command;

class SyncHutCatalog extends Command
{
    protected $signature = 'huts:sync-catalog
        {--from=1 : First hutId to probe}
        {--max=700 : Last hutId to probe}
        {--bbox=46.37,9.53,49.02,17.16 : minLat,minLng,maxLat,maxLng (empty = anywhere)}
        {--countries=AT,DE : Allowed club countries, comma-separated (empty = any)}
        {--sleep=150 : Milliseconds to wait between requests}';

    protected $description = 'Enumerate the Hut Reservation Service and store Austrian hut metadata.';

    public function handle(HutReservationService $service): int
    {
        $from = (int) $this->option('from');
        $max = (int) $this->option('max');
        $sleep = (int) $this->option('sleep') * 1000;

        // Keeping only Austrian huts is a two-part filter, because the API has no
        // physical-country field — only the club's country. A bounding box picks
        // huts by location; a club-country allow-list keeps AT and DE (the German
        // club runs many huts physically in Austria) while dropping Swiss (CH)
        // and South-Tyrolean (IT) huts just over the border.
        $bbox = $this->parseList($this->option('bbox'));
        $bbox = $bbox === [] ? null : array_map('floatval', $bbox);
        $countries = array_map('strtoupper', $this->parseList($this->option('countries')));

        $kept = 0;
        $seen = 0;

        $this->info("Probing hutIds {$from}..{$max}"
            .($bbox ? ' within bbox '.implode(',', $bbox) : '')
            .($countries ? ' clubs '.implode('/', $countries) : ''));
        $bar = $this->output->createProgressBar($max - $from + 1);
        $bar->start();

        for ($id = $from; $id <= $max; $id++) {
            $bar->advance();

            $info = $service->hutInfo($id);
            if ($info !== null) {
                $seen++;
                $hut = $service->normaliseHut($info);

                if ($this->keep($hut, $bbox, $countries)) {
                    Hut::updateOrCreate(
                        ['id' => $hut['id']],
                        $hut + ['catalog_synced_at' => now()],
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
        $this->info("Done. Saw {$seen} huts, stored {$kept}.");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $hut
     * @param  array<int, float>|null  $bbox
     * @param  array<int, string>  $countries
     */
    private function keep(array $hut, ?array $bbox, array $countries): bool
    {
        if ($hut['latitude'] === null || $hut['longitude'] === null) {
            return false;
        }

        if ($bbox !== null) {
            [$minLat, $minLng, $maxLat, $maxLng] = $bbox;
            if ($hut['latitude'] < $minLat || $hut['latitude'] > $maxLat
                || $hut['longitude'] < $minLng || $hut['longitude'] > $maxLng) {
                return false;
            }
        }

        if ($countries !== [] && ! in_array(strtoupper((string) $hut['country']), $countries, true)) {
            return false;
        }

        return true;
    }

    /**
     * @return array<int, string>
     */
    private function parseList(?string $raw): array
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw)), fn ($v) => $v !== ''));
    }
}
