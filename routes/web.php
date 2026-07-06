<?php

use App\Http\Controllers\GeocodeController;
use App\Http\Controllers\HutFinderController;
use Illuminate\Support\Facades\Route;

// Public last-minute Alpine hut finder (Austria).
Route::get('/', [HutFinderController::class, 'index'])->name('huts.index');

// Cached geocoding proxy for the location search + current-location label.
Route::get('/geocode', [GeocodeController::class, 'search'])->name('geocode.search');
Route::get('/geocode/reverse', [GeocodeController::class, 'reverse'])->name('geocode.reverse');
