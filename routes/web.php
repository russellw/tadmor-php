<?php

use App\Http\Controllers\Ui\AuthController;
use App\Http\Controllers\Ui\HomeController;
use App\Http\Controllers\Ui\MasterController;
use Illuminate\Support\Facades\Route;

// The server-rendered UI (spec/domain.md §13). Every page but sign-in needs a session.
Route::match(['get', 'post'], '/login', [AuthController::class, 'login']);

Route::middleware('ui')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/', HomeController::class);

    // M1–M5 master data
    foreach (['organizations', 'customers', 'suppliers', 'products', 'accounts', 'tax-codes', 'payment-terms', 'warehouses'] as $r) {
        Route::get("/$r", [MasterController::class, 'index'])->defaults('resource', $r);
        Route::match(['get', 'post'], "/$r/new", [MasterController::class, 'create'])->defaults('resource', $r);
        Route::match(['get', 'post'], "/$r/{key}", [MasterController::class, 'edit'])->defaults('resource', $r);
    }

    // M7 users, M8 settings
    Route::middleware('admin')->group(function () {
        Route::get('/users', [MasterController::class, 'users']);
        Route::match(['get', 'post'], '/users/new', [MasterController::class, 'newUser']);
        Route::match(['get', 'post'], '/users/{id}', [MasterController::class, 'editUser']);
        Route::match(['get', 'post'], '/users/{id}/password', [MasterController::class, 'userPassword']);
    });
    Route::match(['get', 'post'], '/settings', [MasterController::class, 'settings']);

    require __DIR__.'/web_documents.php';
    require __DIR__.'/web_accounting.php';

    // G8: an unknown address shows a not-found message.
    // (The API answers its own unknown paths; see bootstrap/app.php.)
    Route::fallback(fn () => response()->view('message', [], 404))->where('fallbackPlaceholder', '^(?!api(/|$)).*');
});
