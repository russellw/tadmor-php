<?php

namespace App\Http\Middleware;

use App\Errors\ApiError;
use App\Http\Api;
use Closure;
use Illuminate\Http\Request;

/** Administrator-only endpoints (spec/domain.md §12); runs after authenticated. */
class RequireAdmin
{
    public function handle(Request $request, Closure $next)
    {
        if (! Api::user($request)->is_admin) {
            throw new ApiError(403, 'administrator only');
        }

        return $next($request);
    }
}
