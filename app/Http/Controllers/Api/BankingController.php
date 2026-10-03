<?php

namespace App\Http\Controllers\Api;

use App\Http\Api;
use App\Http\Body;
use App\Services\Banking;
use Illuminate\Http\Request;

/** spec/api.md §5.13. */
class BankingController
{
    public function index()
    {
        return Api::ok(Banking::all());
    }

    public function store(Request $request)
    {
        return Api::created(['id' => Banking::create(Body::from($request), Api::user($request)->id)]);
    }

    public function show(string $id)
    {
        return Api::ok(Banking::json(Banking::get(Api::id($id))));
    }

    public function update(Request $request, string $id)
    {
        $id = Api::id($id);
        Banking::update($id, Body::from($request));

        return Api::noContent();
    }

    public function destroy(string $id)
    {
        Banking::delete(Api::id($id));

        return Api::noContent();
    }

    public function lines(string $id)
    {
        return Api::ok(Banking::lines(Api::id($id)));
    }

    public function addLine(Request $request, string $id)
    {
        $id = Api::id($id);

        return Api::created(['id' => Banking::addLine($id, Body::from($request))]);
    }

    public function import(Request $request, string $id)
    {
        $id = Api::id($id);

        return Api::ok(['imported' => Banking::importCsv($id, Body::from($request))]);
    }

    public function candidates(string $id)
    {
        return Api::ok(Banking::candidates(Api::id($id)));
    }

    public function autoMatch(string $id)
    {
        return Api::ok(['matched' => Banking::autoMatch(Api::id($id))]);
    }

    public function reconcile(string $id)
    {
        Banking::reconcile(Api::id($id));

        return Api::noContent();
    }

    public function reopen(string $id)
    {
        Banking::reopen(Api::id($id));

        return Api::noContent();
    }

    public function match(Request $request, string $id)
    {
        $id = Api::id($id);
        Banking::match($id, Body::from($request));

        return Api::noContent();
    }

    public function unmatch(string $id)
    {
        Banking::unmatch(Api::id($id));

        return Api::noContent();
    }

    public function deleteLine(string $id)
    {
        Banking::deleteLine(Api::id($id));

        return Api::noContent();
    }
}
