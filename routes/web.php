<?php

use App\Http\Controllers\HutFinderController;
use Illuminate\Support\Facades\Route;

// Public last-minute Alpine hut finder (Austria).
Route::get('/', [HutFinderController::class, 'index'])->name('huts.index');
