<?php

namespace App\Console\Commands;

use App\Sources\AbstractHutSource;
use App\Sources\HutSource;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class SyncHuts extends Command
{
    protected $signature = 'huts:sync
        {--source= : Only this source key (e.g. hrs, huetten-holiday)}
        {--catalog : Only refresh the hut catalogue}
        {--availability : Only refresh availability}
        {--days= : Days of availability to cache (default: config huts.horizon_days)}';

    protected $description = 'Sync hut catalogue and/or availability from every configured source.';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('huts.horizon_days', 21));

        // Default (neither flag) runs both phases.
        $doCatalog = $this->option('catalog') || ! $this->option('availability');
        $doAvailability = $this->option('availability') || ! $this->option('catalog');

        $sources = $this->sources();
        if ($sources->isEmpty()) {
            $this->warn('No matching sources.');

            return self::SUCCESS;
        }

        foreach ($sources as $source) {
            $this->line("<info>{$source->label()}</info> ({$source->key()})");

            if ($doCatalog) {
                $n = $source->syncCatalog();
                $this->line("  catalogue: {$n} huts");
            }

            if ($doAvailability) {
                $n = $source->syncAvailability($days);
                $this->line("  availability: {$n} rows");
            }
        }

        if ($doAvailability) {
            AbstractHutSource::prunePast();
        }

        $this->info('Done.');

        return self::SUCCESS;
    }

    /**
     * Resolve the configured sources, optionally filtered by --source.
     *
     * @return Collection<int, HutSource>
     */
    private function sources()
    {
        $only = $this->option('source');

        return collect(config('huts.sources', []))
            ->map(fn (string $class) => app($class))
            ->filter(fn (HutSource $s) => $only === null || $s->key() === $only)
            ->values();
    }
}
