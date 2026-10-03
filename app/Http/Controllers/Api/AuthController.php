<?php

namespace App\Http\Controllers\Api;

use App\Errors\ApiError;
use App\Http\Api;
use App\Http\Body;
use App\Services\Sessions;
use App\Services\Users;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** spec/api.md §3. */
class AuthController
{
    public function login(Request $request): JsonResponse
    {
        $body = Body::from($request);
        $email = trim($body->str('email') ?? '');
        $password = $body->str('password') ?? '';
        if ($email === '' || $password === '') {
            throw new ApiError(400, 'email and password are required');
        }
        $user = Users::authenticate($email, $password);
        if ($user === null) {
            throw new ApiError(401, 'invalid email or password');
        }
        $token = Sessions::create($user);

        return response()->json($user->toApi())->withCookie(Sessions::cookie($request, $token));
    }

    public function logout(Request $request): Response
    {
        Sessions::revoke($request);

        return response()->noContent()->withCookie(Sessions::clearingCookie($request));
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(Api::user($request)->toApi());
    }
}
