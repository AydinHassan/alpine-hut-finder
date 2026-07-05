<?php

namespace App\Http\Controllers;

use App\Models\Hut;
use App\Models\HutAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class HutFinderController extends Controller
{
    /**
     * Public "last-minute huts with free beds near me" page. All the data the
     * client needs (huts + upcoming availability) is embedded once; the browser
     * handles geolocation, distance sorting and date filtering.
     */
    public function index(Request $request): View
    {
        $days = max(1, min((int) $request->integer('days', 14), 21));

        $today = CarbonImmutable::today();
        $horizon = $today->addDays($days);

        $huts = Hut::query()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->with(['availabilities' => function ($query) use ($today, $horizon) {
                $query->whereBetween('date', [$today->toDateString(), $horizon->toDateString()])
                    ->orderBy('date');
            }])
            ->get()
            // Only ship huts that actually have a free bed somewhere in the window.
            ->filter(fn (Hut $hut) => $hut->availabilities->contains(fn ($a) => $a->free_beds > 0))
            ->map(fn (Hut $hut) => [
                'id' => $hut->id,
                'name' => $hut->name,
                'club' => $hut->club,
                'lat' => $hut->latitude,
                'lng' => $hut->longitude,
                'altitude' => $hut->altitude,
                'totalBeds' => $hut->total_beds,
                'bookingUrl' => $hut->bookingUrl(),
                'nights' => $hut->availabilities->map(fn ($a) => [
                    'date' => $a->date->toDateString(),
                    'freeBeds' => $a->free_beds,
                    'percentage' => $a->percentage,
                ])->values(),
            ])
            ->values();

        $payload = [
            'huts' => $huts,
            'days' => $days,
            'today' => $today->toDateString(),
            'updatedAt' => HutAvailability::query()->max('fetched_at')
                ?? Hut::query()->max('catalog_synced_at'),
        ];

        return view('huts', ['payload' => $payload]);
    }
}
