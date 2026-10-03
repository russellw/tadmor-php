<?php

namespace App\Http\Controllers\Api;

use App\Http\Api;
use App\Http\Body;
use App\Services\Posting;
use App\Services\Stock;
use Illuminate\Http\Request;

/** spec/api.md §5.12. */
class StockController
{
    public function index()
    {
        return Api::ok(Stock::all());
    }

    public function store(Request $request)
    {
        return Api::created(['id' => Stock::create(Body::from($request), Api::user($request)->id)]);
    }

    public function show(string $id)
    {
        return Api::ok(Stock::json(Stock::get(Api::id($id))));
    }

    public function update(Request $request, string $id)
    {
        $id = Api::id($id);
        Stock::update($id, Body::from($request));

        return Api::noContent();
    }

    public function destroy(string $id)
    {
        Stock::delete(Api::id($id));

        return Api::noContent();
    }

    public function post(Request $request, string $id)
    {
        $id = Api::id($id);

        return Api::ok(['journal_entry_id' => Posting::postMovement($id, Body::from($request)->int('credit_account_id'))]);
    }

    public function unpost(string $id)
    {
        return Api::ok(['reversal_entry_id' => Posting::unpostMovement(Api::id($id))]);
    }
}
