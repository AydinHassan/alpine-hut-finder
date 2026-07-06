<?php

use App\Sources\AlpenvereinSource;
use App\Sources\HuettenHolidaySource;

return [

    /*
    |--------------------------------------------------------------------------
    | Hut data sources
    |--------------------------------------------------------------------------
    |
    | Every platform we pull huts from. `huts:sync` drives each through the same
    | catalogue + availability phases. To add a platform: implement
    | App\Sources\HutSource and add its class here.
    |
    | Order matters: huetten-holiday runs first so the Alpenverein directory
    | dedupes against it (an opted-out section hut is bookable on huetten-holiday
    | but only book-direct in the directory — keep the bookable one).
    |
    */

    'sources' => [
        HuettenHolidaySource::class,
        AlpenvereinSource::class,
    ],

    // Days of availability to cache ahead.
    'horizon_days' => 21,

];
