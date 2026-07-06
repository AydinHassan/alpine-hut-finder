<?php

namespace App\Sources;

/**
 * A source of Austrian hut data. Each booking platform we pull from implements
 * this once; the huts:sync command drives every registered source through the
 * same two phases, so adding a platform is a single class + one line in
 * config/huts.php — no new commands, no duplicated upsert/filter logic.
 */
interface HutSource
{
    /** Stable identifier stored on huts.source (e.g. 'hrs', 'huetten-holiday'). */
    public function key(): string;

    /** Human-readable name for CLI output. */
    public function label(): string;

    /** Whether this source provides queryable online availability. */
    public function providesAvailability(): bool;

    /**
     * Fetch this source's catalogue and upsert the huts physically in Austria.
     *
     * @return int number of huts stored
     */
    public function syncCatalog(): int;

    /**
     * Refresh cached per-day availability for this source's huts.
     *
     * @return int number of availability rows written
     */
    public function syncAvailability(int $days): int;
}
