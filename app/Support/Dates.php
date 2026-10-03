<?php

namespace App\Support;

use App\Errors\ApiError;
use DateTimeImmutable;
use DateTimeZone;

/** Dates travel as YYYY-MM-DD strings, which also compare correctly as text. */
final class Dates
{
    /** A YYYY-MM-DD date, or $status (422 in bodies, 400 in paths and queries). */
    public static function parse(string $text, string $name = 'date', int $status = 422): string
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $text, $m)) {
            throw new ApiError($status, "$name must be a YYYY-MM-DD date");
        }
        if (! checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || $m[1] === '0000') {
            throw new ApiError($status, "$name must be a valid YYYY-MM-DD date");
        }

        return $text;
    }

    /** Today's date in UTC, whatever the server's timezone (spec/api.md §1.2). */
    public static function today(): string
    {
        return gmdate('Y-m-d');
    }

    public static function addDays(string $date, int $days): string
    {
        return self::at($date)->modify(sprintf('%+d days', $days))->format('Y-m-d');
    }

    public static function monthStart(string $date): string
    {
        return substr($date, 0, 8).'01';
    }

    public static function monthEnd(string $date): string
    {
        return self::at($date)->format('Y-m-t');
    }

    /** The same day a year later; 29 February becomes 28 February. */
    public static function plusYear(string $date): string
    {
        [$y, $m, $d] = array_map('intval', explode('-', $date));
        if ($m === 2 && $d === 29) {
            $d = 28;
        }

        return sprintf('%04d-%02d-%02d', $y + 1, $m, $d);
    }

    private static function at(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date, new DateTimeZone('UTC'));
    }
}
