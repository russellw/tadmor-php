<?php

use App\Http\Controllers\Ui\AccountingController as A;
use App\Http\Controllers\Ui\ReportController as R;
use Illuminate\Support\Facades\Route;

// S1–S3 stock movements
Route::get('/stock-movements', [A::class, 'movements']);
Route::match(['get', 'post'], '/stock-movements/new', [A::class, 'newMovement']);
Route::get('/stock-movements/{id}', [A::class, 'showMovement']);
Route::match(['get', 'post'], '/stock-movements/{id}/edit', [A::class, 'editMovement']);
Route::match(['get', 'post'], '/stock-movements/{id}/delete', [A::class, 'deleteMovement']);
foreach (['post', 'unpost'] as $action) {
    Route::match(['get', 'post'], "/stock-movements/{id}/$action", [A::class, 'movementAction'])->defaults('action', $action);
}

// A1, A2 periods and year-end
Route::get('/periods', [A::class, 'periods']);
Route::match(['get', 'post'], '/fiscal-years/new', [A::class, 'newYear']);
Route::match(['get', 'post'], '/fiscal-years/{id}', [A::class, 'editYear']);
Route::middleware('admin')->group(function () {
    Route::match(['get', 'post'], '/fiscal-years/{id}/close', [A::class, 'closeYear']);
    Route::match(['get', 'post'], '/fiscal-years/{id}/reopen', [A::class, 'reopenYear']);
});
Route::match(['get', 'post'], '/accounting-periods/new', [A::class, 'newPeriod']);
Route::match(['get', 'post'], '/accounting-periods/{id}', [A::class, 'editPeriod']);
Route::match(['get', 'post'], '/accounting-periods/{id}/toggle', [A::class, 'togglePeriod']);

// A3 exchange rates
Route::get('/exchange-rates', [A::class, 'rates']);
Route::match(['get', 'post'], '/exchange-rates/new', [A::class, 'newRate']);
Route::match(['get', 'post'], '/exchange-rates/{currency}/{date}', [A::class, 'editRate']);
Route::match(['get', 'post'], '/exchange-rates/{currency}/{date}/delete', [A::class, 'deleteRate']);

// A4, A5 bank statements
Route::get('/bank-statements', [A::class, 'statements']);
Route::match(['get', 'post'], '/bank-statements/new', [A::class, 'newStatement']);
Route::get('/bank-statements/{id}', [A::class, 'showStatement']);
Route::match(['get', 'post'], '/bank-statements/{id}/edit', [A::class, 'editStatement']);
Route::match(['get', 'post'], '/bank-statements/{id}/delete', [A::class, 'deleteStatement']);
foreach (['add' => 'lines', 'import' => 'import', 'auto' => 'auto-match', 'reconcile' => 'reconcile', 'reopen' => 'reopen'] as $action => $path) {
    Route::match(['get', 'post'], "/bank-statements/{id}/$path", [A::class, 'statementAction'])->defaults('action', $action);
}
foreach (['match', 'unmatch', 'delete'] as $action) {
    Route::match(['get', 'post'], "/bank-statements/{id}/lines/{line}/$action", [A::class, 'lineAction'])->defaults('action', $action);
}

// R1–R8 reports
Route::get('/reports/profit-and-loss', [R::class, 'profitAndLoss']);
Route::get('/reports/balance-sheet', [R::class, 'balanceSheet']);
Route::get('/reports/cash-flow', [R::class, 'cashFlow']);
Route::get('/reports/trial-balance', [R::class, 'trialBalance']);
Route::get('/reports/ar-aging', [R::class, 'arAging']);
Route::get('/reports/ap-aging', [R::class, 'apAging']);
Route::get('/inventory-valuation', [R::class, 'inventoryValuation']);
Route::get('/accounts/{id}/ledger', [R::class, 'ledger']);
Route::get('/journal-entries/{id}', [R::class, 'journalEntry']);
