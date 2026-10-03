<?php

namespace App\Http\Middleware;

use App\Errors\ApiError;
use App\Services\Sessions;
use Closure;
use Illuminate\Http\Request;

/** Requires a live session; the user is re-read on every request. */
class Authenticate
{
    public function handle(Request $request, Closure $next)
    {
        $user = Sessions::fromRequest($request);
        if ($user === null) {
            throw new ApiError(401, 'not authenticated');
        }
        $request->attributes->set('user', $user);

        return $next($request);
    }
}
