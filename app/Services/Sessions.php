<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Login sessions over the shared sessions table (spec/api.md §3). The cookie
 * carries a random token; the table holds only its SHA-256 hash.
 */
class Sessions
{
    public const COOKIE = 'tadmor_session';

    /** Sessions last a fixed 30 days from login, as tadmor's do. */
    private const LIFETIME_DAYS = 30;

    /** Start a session for the user and return its token. */
    public static function create(User $user): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        DB::delete('DELETE FROM sessions WHERE expires_at <= now()');
        DB::insert(
            "INSERT INTO sessions (token_hash, user_id, expires_at)
             VALUES (decode(?, 'hex'), ?, now() + make_interval(days => ?))",
            [hash('sha256', $token), $user->id, self::LIFETIME_DAYS],
        );

        return $token;
    }

    /** The live, active user the request's session belongs to, if any. */
    public static function fromRequest(Request $request): ?User
    {
        $token = $request->cookies->get(self::COOKIE);
        if (! is_string($token) || $token === '') {
            return null;
        }

        return User::query()
            ->join('sessions', 'sessions.user_id', '=', 'users.id')
            ->whereRaw("sessions.token_hash = decode(?, 'hex')", [hash('sha256', $token)])
            ->where('sessions.expires_at', '>', DB::raw('now()'))
            ->where('users.is_active', true)
            ->first(['users.*']);
    }

    public static function revoke(Request $request): void
    {
        $token = $request->cookies->get(self::COOKIE);
        if (is_string($token) && $token !== '') {
            DB::delete("DELETE FROM sessions WHERE token_hash = decode(?, 'hex')", [hash('sha256', $token)]);
        }
    }

    public static function revokeAll(int $userId): void
    {
        DB::delete('DELETE FROM sessions WHERE user_id = ?', [$userId]);
    }

    public static function cookie(Request $request, string $token): Cookie
    {
        return Cookie::create(self::COOKIE, $token)
            ->withExpires(time() + self::LIFETIME_DAYS * 86400)
            ->withPath('/')
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_LAX)
            ->withSecure(self::isHttps($request))
            ->withRaw(true);
    }

    public static function clearingCookie(Request $request): Cookie
    {
        return self::cookie($request, '')->withExpires(1);
    }

    private static function isHttps(Request $request): bool
    {
        return $request->isSecure() || strtolower((string) $request->headers->get('X-Forwarded-Proto')) === 'https';
    }
}
