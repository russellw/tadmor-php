<?php

namespace App\Http\Controllers\Api;

use App\Http\Api;
use App\Http\Body;
use App\Services\Users;
use Illuminate\Http\Request;

/** spec/api.md §5.1 (all administrator-only). */
class UserController
{
    public function index()
    {
        return Api::ok(Users::all());
    }

    public function show(string $id)
    {
        return Api::ok(Users::json(Users::get(Api::id($id))));
    }

    public function store(Request $request)
    {
        return Api::created(['id' => Users::create(Body::from($request))]);
    }

    public function update(Request $request, string $id)
    {
        $id = Api::id($id);
        Users::update(Api::user($request), $id, Body::from($request));

        return Api::noContent();
    }

    public function password(Request $request, string $id)
    {
        $id = Api::id($id);
        Users::setPassword($id, Body::from($request));

        return Api::noContent();
    }
}
