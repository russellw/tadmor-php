<?php

namespace App\Http;

use App\Errors\ApiError;
use App\Models\User;
use Illuminate\Http\Request;

/** Request helpers shared by the API controllers. */
class Api
{
    /** The JSON object body, or 400 (spec/api.md §1.4). */
    public static function body(Request $request): array
    {
        $content = $request->getContent();
        if ($content === '') {
            throw new ApiError(400, 'request body must be a JSON object');
        }
        try {
            $body = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiError(400, 'request body is not valid JSON');
        }
        if (! is_array($body) || array_is_list($body) && $body !== []) {
            throw new ApiError(400, 'request body must be a JSON object');
        }

        return $body;
    }

    /** A string field; absent, null, or a non-string is a 400. */
    public static function string(array $body, string $field): string
    {
        $value = $body[$field] ?? null;
        if (! is_string($value)) {
            throw new ApiError(400, "$field is required");
        }

        return $value;
    }

    /** The session's user, set by the authenticated middleware. */
    public static function user(Request $request): User
    {
        return $request->attributes->get('user');
    }
}
