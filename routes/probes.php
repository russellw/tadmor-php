<?php

use App\Http\Controllers\ProbeController;
use Illuminate\Support\Facades\Route;

// spec/api.md §2: no authentication.
Route::get('/healthz', [ProbeController::class, 'health']);
Route::get('/readyz', [ProbeController::class, 'ready']);
