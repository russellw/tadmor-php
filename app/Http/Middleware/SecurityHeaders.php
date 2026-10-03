<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Same-origin only: every script, style, and image is served by us. */
class SecurityHeaders
{
    private const POLICY = "default-src 'self'; img-src 'self' data:; frame-ancestors 'none'; form-action 'self'; base-uri 'self'";

    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        $response->headers->set('Content-Security-Policy', self::POLICY, false);
        $response->headers->set('X-Content-Type-Options', 'nosniff', false);

        return $response;
    }
}
