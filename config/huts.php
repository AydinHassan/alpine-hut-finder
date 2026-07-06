<?php

use App\Sources\HrsSource;
use App\Sources\HuettenHolidaySource;

return [

    /*
    |--------------------------------------------------------------------------
    | Hut data sources
    |--------------------------------------------------------------------------
    |
    | Every booking platform we pull availability from. `huts:sync` drives each
    | through the same catalogue + availability phases. To add a platform:
    | implement App\Sources\HutSource and add its class here — nothing else.
    |
    */

    'sources' => [
        HrsSource::class,
        HuettenHolidaySource::class,
    ],

    // Days of availability to cache ahead.
    'horizon_days' => 21,

];
