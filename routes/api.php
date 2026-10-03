<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BankingController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\LedgerController;
use App\Http\Controllers\Api\MasterController;
use App\Http\Controllers\Api\StockController;
use App\Http\Controllers\Api\UserController;
use App\Services\Kinds;
use Illuminate\Support\Facades\Route;

// spec/api.md §3: everything but login and logout needs a session.
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/logout', [AuthController::class, 'logout']);

Route::middleware('authenticated')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);

    // §5.1 Users
    Route::middleware('admin')->controller(UserController::class)->group(function () {
        Route::get('/users', 'index');
        Route::post('/users', 'store');
        Route::get('/users/{id}', 'show');
        Route::put('/users/{id}', 'update');
        Route::post('/users/{id}/password', 'password');
    });

    // §5.2–5.7 Master data and the fiscal calendar
    $master = ['organizations', 'customers', 'suppliers', 'products', 'accounts', 'warehouses',
        'tax-codes', 'payment-terms', 'fiscal-years', 'accounting-periods'];
    foreach ($master as $resource) {
        Route::controller(MasterController::class)->group(function () use ($resource) {
            Route::get("/$resource", 'index')->defaults('resource', $resource);
            Route::post("/$resource", 'store')->defaults('resource', $resource);
            Route::get("/$resource/{key}", 'show')->defaults('resource', $resource);
            Route::put("/$resource/{key}", 'update')->defaults('resource', $resource);
        });
    }

    Route::controller(LedgerController::class)->group(function () {
        Route::post('/fiscal-years/{id}/close', 'closeYear')->middleware('admin');
        Route::post('/fiscal-years/{id}/reopen', 'reopenYear')->middleware('admin');

        // §5.8 Settings and exchange rates
        Route::get('/settings', 'settings');
        Route::put('/settings', 'updateSettings')->middleware('admin');
        Route::get('/exchange-rates', 'exchangeRates');
        Route::post('/exchange-rates', 'createExchangeRate');
        Route::put('/exchange-rates/{currency}/{date}', 'updateExchangeRate');
        Route::delete('/exchange-rates/{currency}/{date}', 'deleteExchangeRate');

        // §5.14 Journal and reports
        Route::get('/journal-entries/{id}', 'journalEntry');
        Route::get('/accounts/{id}/ledger', 'ledger');
        Route::get('/trial-balance', 'trialBalance');
        Route::get('/profit-and-loss', 'profitAndLoss');
        Route::get('/balance-sheet', 'balanceSheet');
        Route::get('/cash-flow', 'cashFlow');
        Route::get('/ar-aging', 'arAging');
        Route::get('/ap-aging', 'apAging');
        Route::get('/inventory-valuation', 'inventoryValuation');
    });

    Route::controller(DocumentController::class)->group(function () {
        // §5.9 Invoices, bills, credit notes
        foreach (Kinds::$documents as $c => $kind) {
            Route::get("/$c", 'index')->defaults('collection', $c);
            Route::post("/$c", 'store')->defaults('collection', $c);
            Route::get("/$c/{id}", 'show')->defaults('collection', $c);
            Route::put("/$c/{id}", 'update')->defaults('collection', $c);
            Route::delete("/$c/{id}", 'destroy')->defaults('collection', $c);
            Route::get("/$c/{id}/lines", 'lines')->defaults('collection', $c);
            Route::post("/$c/{id}/post", 'post')->defaults('collection', $c);
            Route::post("/$c/{id}/unpost", 'unpost')->defaults('collection', $c)->middleware('admin');
            if ($kind->credit) {
                Route::get("/$c/{id}/applications", 'applications')->defaults('collection', $c);
                Route::post("/$c/{id}/apply", 'apply')->defaults('collection', $c);
            }
        }

        // §5.9 Payments
        foreach (array_keys(Kinds::$payments) as $c) {
            Route::get("/$c", 'paymentIndex')->defaults('collection', $c);
            Route::post("/$c", 'paymentStore')->defaults('collection', $c);
            Route::get("/$c/{id}", 'paymentShow')->defaults('collection', $c);
            Route::put("/$c/{id}", 'paymentUpdate')->defaults('collection', $c);
            Route::delete("/$c/{id}", 'paymentDestroy')->defaults('collection', $c);
            Route::get("/$c/{id}/applications", 'paymentApplications')->defaults('collection', $c);
            Route::post("/$c/{id}/post", 'paymentPost')->defaults('collection', $c);
            Route::post("/$c/{id}/unpost", 'paymentUnpost')->defaults('collection', $c)->middleware('admin');
            Route::post("/$c/{id}/apply", 'apply')->defaults('collection', $c);
        }

        // §5.10 Orders
        foreach (Kinds::$orders as $c => $kind) {
            Route::get("/$c", 'orderIndex')->defaults('collection', $c);
            Route::post("/$c", 'orderStore')->defaults('collection', $c);
            Route::get("/$c/{id}", 'orderShow')->defaults('collection', $c);
            Route::put("/$c/{id}", 'orderUpdate')->defaults('collection', $c);
            Route::delete("/$c/{id}", 'orderDestroy')->defaults('collection', $c);
            Route::get("/$c/{id}/lines", 'orderLines')->defaults('collection', $c);
            foreach (['confirm', 'close', 'cancel'] as $action) {
                Route::post("/$c/{id}/$action", 'orderTransition')->defaults('collection', $c)->defaults('action', $action);
            }
            Route::post("/$c/{id}/{$kind->billVerb}", 'orderInvoice')->defaults('collection', $c);
            Route::post("/$c/{id}/{$kind->moveVerb}", 'orderMove')->defaults('collection', $c);
        }

        // §5.11 Printing and email
        foreach ([...array_keys(Kinds::$documents), ...array_keys(Kinds::$orders)] as $c) {
            Route::get("/$c/{id}/pdf", 'pdf')->defaults('collection', $c);
            Route::post("/$c/{id}/email", 'email')->defaults('collection', $c);
        }
    });

    // §5.12 Stock movements
    Route::controller(StockController::class)->group(function () {
        Route::get('/stock-movements', 'index');
        Route::post('/stock-movements', 'store');
        Route::get('/stock-movements/{id}', 'show');
        Route::put('/stock-movements/{id}', 'update');
        Route::delete('/stock-movements/{id}', 'destroy');
        Route::post('/stock-movements/{id}/post', 'post');
        Route::post('/stock-movements/{id}/unpost', 'unpost')->middleware('admin');
    });

    // §5.13 Bank reconciliation
    Route::controller(BankingController::class)->group(function () {
        Route::get('/bank-statements', 'index');
        Route::post('/bank-statements', 'store');
        Route::get('/bank-statements/{id}', 'show');
        Route::put('/bank-statements/{id}', 'update');
        Route::delete('/bank-statements/{id}', 'destroy');
        Route::get('/bank-statements/{id}/lines', 'lines');
        Route::post('/bank-statements/{id}/lines', 'addLine');
        Route::post('/bank-statements/{id}/import', 'import');
        Route::get('/bank-statements/{id}/candidates', 'candidates');
        Route::post('/bank-statements/{id}/auto-match', 'autoMatch');
        Route::post('/bank-statements/{id}/reconcile', 'reconcile');
        Route::post('/bank-statements/{id}/reopen', 'reopen')->middleware('admin');
        Route::post('/bank-statement-lines/{id}/match', 'match');
        Route::post('/bank-statement-lines/{id}/unmatch', 'unmatch');
        Route::delete('/bank-statement-lines/{id}', 'deleteLine');
    });
});
