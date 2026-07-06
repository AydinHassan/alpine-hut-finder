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
     * client needs (huts + upcoming availability, plus the opt-in book-direct
     * huts) is embedded once; the browser handles geolocation, distance sorting
     * and date filtering.
     */
    public function index(Request $request): View
    {
        $days = max(1, min((int) $request->integer('days', 14), 21));

        $today = CarbonImmutable::today();
        $horizon = $today->addDays($days);

        $inWindow = fn ($query) => $query->whereBetween('date', [$today->toDateString(), $horizon->toDateString()]);

        // Huts we track availability for, that have a free bed in the window.
        $huts = Hut::query()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->whereHas('availabilities', fn ($q) => $inWindow($q)->where('free_beds', '>', 0))
            ->with(['availabilities' => fn ($q) => $inWindow($q)->orderBy('date')])
            ->get()
            ->map(fn (Hut $hut) => [
                'id' => $hut->id,
                'name' => $hut->name,
                'club' => $hut->club,
                'lat' => $hut->latitude,
                'lng' => $hut->longitude,
                'altitude' => $hut->altitude,
                'totalBeds' => $hut->total_beds,
                'website' => $hut->website,
                'bookingUrl' => $hut->bookingUrl(),
                'nights' => $hut->availabilities->map(fn ($a) => [
                    'date' => $a->date->toDateString(),
                    'freeBeds' => $a->free_beds,
                    'percentage' => $a->percentage,
                ])->values(),
            ])
            ->values();

        // Book-direct huts: sources without an online booking system (phone/email
        // only). A booked-out *online* hut is bookable_online=true and simply has
        // no free beds — it is NOT shown here. Behind an opt-in toggle.
        $manualHuts = Hut::query()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->where('bookable_online', false)
            ->where(fn ($q) => $q->whereNotNull('phone')->orWhereNotNull('email')->orWhereNotNull('website'))
            ->orderBy('name')
            ->get()
            ->map(fn (Hut $hut) => [
                'id' => $hut->id,
                'name' => $hut->name,
                'club' => $hut->club,
                'lat' => $hut->latitude,
                'lng' => $hut->longitude,
                'altitude' => $hut->altitude,
                'phone' => $hut->phone,
                'email' => $hut->email,
                'website' => $hut->website,
            ])
            ->values();

        $payload = [
            'huts' => $huts,
            'manualHuts' => $manualHuts,
            'days' => $days,
            'today' => $today->toDateString(),
            'updatedAt' => HutAvailability::query()->max('fetched_at')
                ?? Hut::query()->max('catalog_synced_at'),
        ];

        return view('huts', ['payload' => $payload]);
    }
}
