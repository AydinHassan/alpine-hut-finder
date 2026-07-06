<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Keep cached bed availability fresh across every configured source. The hut
// catalogue barely changes, so it is re-scanned weekly; availability is polled
// hourly. Both are gentle on the upstreams (cached, rate-limited) and never run
// on a page view.
Schedule::command('huts:sync --availability')->hourly()->withoutOverlapping();
Schedule::command('huts:sync --catalog')->weekly()->sundays()->at('03:00')->withoutOverlapping();
