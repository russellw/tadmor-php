<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;

// spec/api.md §3: everything but login and logout needs a session.
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/logout', [AuthController::class, 'logout']);

Route::middleware('authenticated')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
});
