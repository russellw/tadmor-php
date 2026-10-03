<?php

namespace App\Http;

use App\Errors\ApiError;
use App\Models\User;
use App\Support\Dates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Request and response helpers shared by the API controllers. */
final class Api
{
    /** A path id: a positive integer, else 400 (spec/api.md §1.4). */
    public static function id(string $text, string $name = 'id'): int
    {
        if (! ctype_digit($text) || strlen($text) > 9 || (int) $text <= 0) {
            throw new ApiError(400, "invalid $name");
        }

        return (int) $text;
    }

    /** An optional date query parameter; malformed is 400. */
    public static function dateParam(Request $request, string $name): ?string
    {
        $v = $request->query($name);
        if ($v === null || $v === '') {
            return null;
        }
        if (! is_string($v)) {
            throw new ApiError(400, "$name must be a YYYY-MM-DD date");
        }

        return Dates::parse($v, $name, 400);
    }

    /** The session's user, set by the authenticated middleware. */
    public static function user(Request $request): User
    {
        return $request->attributes->get('user');
    }

    public static function ok(mixed $data): JsonResponse
    {
        return response()->json($data, 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function created(array $data): JsonResponse
    {
        return response()->json($data, 201, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function noContent(): Response
    {
        return response()->noContent();
    }
}
