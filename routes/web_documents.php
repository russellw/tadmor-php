<?php

use App\Http\Controllers\Ui\DocumentController as D;
use App\Services\Kinds;
use Illuminate\Support\Facades\Route;

// D1–D7 invoices, bills, credit notes
foreach (array_keys(Kinds::$documents) as $c) {
    Route::get("/$c", [D::class, 'index'])->defaults('collection', $c);
    Route::match(['get', 'post'], "/$c/new", [D::class, 'create'])->defaults('collection', $c);
    Route::get("/$c/{id}", [D::class, 'show'])->defaults('collection', $c);
    Route::match(['get', 'post'], "/$c/{id}/edit", [D::class, 'edit'])->defaults('collection', $c);
    Route::match(['get', 'post'], "/$c/{id}/delete", [D::class, 'delete'])->defaults('collection', $c);
    foreach (['post', 'unpost', 'apply'] as $action) {
        Route::match(['get', 'post'], "/$c/{id}/$action", [D::class, 'action'])->defaults('collection', $c)->defaults('action', $action);
    }
}

// P1–P4 payments
foreach (array_keys(Kinds::$payments) as $c) {
    Route::get("/$c", [D::class, 'paymentIndex'])->defaults('collection', $c);
    Route::match(['get', 'post'], "/$c/new", [D::class, 'paymentCreate'])->defaults('collection', $c);
    Route::get("/$c/{id}", [D::class, 'paymentShow'])->defaults('collection', $c);
    Route::match(['get', 'post'], "/$c/{id}/edit", [D::class, 'paymentEdit'])->defaults('collection', $c);
    Route::match(['get', 'post'], "/$c/{id}/delete", [D::class, 'paymentDelete'])->defaults('collection', $c);
    foreach (['post', 'unpost', 'apply'] as $action) {
        Route::match(['get', 'post'], "/$c/{id}/$action", [D::class, 'paymentAction'])->defaults('collection', $c)->defaults('action', $action);
    }
}

// O1–O7 orders
foreach (Kinds::$orders as $c => $kind) {
    Route::get("/$c", [D::class, 'orderIndex'])->defaults('collection', $c);
    Route::match(['get', 'post'], "/$c/new", [D::class, 'orderCreate'])->defaults('collection', $c);
    Route::get("/$c/{id}", [D::class, 'orderShow'])->defaults('collection', $c);
    Route::match(['get', 'post'], "/$c/{id}/edit", [D::class, 'orderEdit'])->defaults('collection', $c);
    Route::match(['get', 'post'], "/$c/{id}/delete", [D::class, 'orderDelete'])->defaults('collection', $c);
    foreach (['confirm', 'close', 'cancel'] as $action) {
        Route::match(['get', 'post'], "/$c/{id}/$action", [D::class, 'orderAction'])->defaults('collection', $c)->defaults('action', $action);
    }
    Route::match(['get', 'post'], "/$c/{id}/{$kind->billVerb}", [D::class, 'orderBill'])->defaults('collection', $c);
    Route::match(['get', 'post'], "/$c/{id}/{$kind->moveVerb}", [D::class, 'orderMove'])->defaults('collection', $c);
}

// D7, O7 email
foreach ([...array_keys(Kinds::$documents), ...array_keys(Kinds::$orders)] as $c) {
    Route::match(['get', 'post'], "/$c/{id}/email", [D::class, 'email'])->defaults('collection', $c);
}
