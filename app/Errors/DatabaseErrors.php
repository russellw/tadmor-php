<?php

namespace App\Errors;

use Illuminate\Database\QueryException;

/**
 * Database errors caused by the request rather than the server: the schema
 * enforces uniqueness, foreign keys, checks, and (in triggers) many business
 * rules, and those refusals become 409 and 422 by their SQLSTATE.
 */
final class DatabaseErrors
{
    private const STATUS_BY_SQLSTATE = [
        '23505' => 409, // unique_violation
        '23503' => 422, // foreign_key_violation
        '23514' => 422, // check_violation
        '23P01' => 422, // exclusion_violation (overlapping periods)
        '23502' => 422, // not_null_violation
        '22003' => 422, // numeric_value_out_of_range
        '22007' => 422, // invalid_datetime_format
        '22008' => 422, // datetime_field_overflow
        '22P02' => 422, // invalid_text_representation
        'P0001' => 422, // raise_exception from a trigger
    ];

    /** The ApiError for a database error, or null if it is a server fault. */
    public static function map(QueryException $e): ?ApiError
    {
        $state = (string) ($e->errorInfo[0] ?? $e->getCode());
        $status = self::STATUS_BY_SQLSTATE[$state] ?? null;
        if ($status === null) {
            return null;
        }
        $detail = (string) ($e->errorInfo[2] ?? $e->getMessage());

        return new ApiError($status, self::friendly($state, $detail) ?? self::primary($detail));
    }

    /** A message naming the offending column, for the common constraint kinds. */
    private static function friendly(string $state, string $detail): ?string
    {
        preg_match('/constraint "([^"]+)"/', $detail, $c);
        preg_match('/(?:on|into) table "([^"]+)"/', $detail, $t);
        $constraint = $c[1] ?? '';
        $table = $t[1] ?? '';
        $column = $table !== '' && str_starts_with($constraint, $table.'_')
            ? substr($constraint, strlen($table) + 1)
            : $constraint;
        if ($state === '23503' && str_ends_with($column, '_fkey')) {
            return 'unknown '.substr($column, 0, -5);
        }
        if ($state === '23505') {
            return 'a record with the same key already exists';
        }
        if ($state === '23P01') {
            return 'overlaps an existing record';
        }

        return null;
    }

    /** The primary message of a Postgres error, without SQLSTATE, DETAIL, or CONTEXT. */
    private static function primary(string $detail): string
    {
        $line = strtok($detail, "\n") ?: $detail;

        return trim(preg_replace('/^ERROR:\s*/', '', $line));
    }
}
