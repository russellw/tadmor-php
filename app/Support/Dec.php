<?php

namespace App\Support;

use App\Errors\ApiError;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Exact decimals (spec/api.md §1.2, domain §2). Values arrive as strings and
 * are rounded half away from zero to their stored scale before anything
 * checks or uses them. brick/math (part of Laravel's tree) does the
 * arithmetic, so nothing passes through binary floating point.
 */
final class Dec
{
    /** [scale, magnitude limit] per kind of decimal. */
    public const MONEY = [4, '1000000000000000'];

    public const RATE = [4, '1000']; // a tax rate in percent

    public const FX = [8, '100000000000']; // an exchange rate

    private const PATTERN = '/^[+-]?(\d+(\.\d*)?|\.\d+)$/';

    public static function of(BigDecimal|string|int|null $v): BigDecimal
    {
        return $v instanceof BigDecimal ? $v : BigDecimal::of($v ?? 0);
    }

    public static function zero(): BigDecimal
    {
        return BigDecimal::zero();
    }

    /** Parse a plain decimal string, rounded to the kind's scale; 422 if it is not one. */
    public static function parse(string $text, array $kind = self::MONEY, string $name = 'value'): BigDecimal
    {
        $text = trim($text);
        if (! preg_match(self::PATTERN, $text)) {
            throw new ApiError(422, "$name must be a decimal number");
        }
        [$scale, $limit] = $kind;
        $d = BigDecimal::of(ltrim($text, '+'))->toScale($scale, RoundingMode::HalfUp);
        if ($d->abs()->compareTo($limit) >= 0) {
            throw new ApiError(422, "$name is out of range");
        }

        return $d;
    }

    public static function round4(BigDecimal|string $d): BigDecimal
    {
        return self::of($d)->toScale(4, RoundingMode::HalfUp);
    }

    /** Refuse a computed amount at or beyond the money limit. */
    public static function checkMagnitude(BigDecimal $d, string $name = 'amount'): BigDecimal
    {
        if ($d->abs()->compareTo(self::MONEY[1]) >= 0) {
            throw new ApiError(422, "$name is out of range");
        }

        return $d;
    }

    /** Money and quantities: scale 4, e.g. "9.9900". */
    public static function fmt4(BigDecimal|string|null $d): ?string
    {
        return $d === null ? null : self::round4($d)->toString();
    }

    /** Exchange rates: trailing zeros trimmed, e.g. "1.125". */
    public static function fmtRate(BigDecimal|string|null $d): ?string
    {
        if ($d === null) {
            return null;
        }
        $s = self::of($d)->toString();

        return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
    }

    public static function min(BigDecimal $a, BigDecimal $b): BigDecimal
    {
        return $a->compareTo($b) <= 0 ? $a : $b;
    }

    public static function max(BigDecimal $a, BigDecimal $b): BigDecimal
    {
        return $a->compareTo($b) >= 0 ? $a : $b;
    }

    /** max(v, 0), for splitting a signed amount into its two sides. */
    public static function pos(BigDecimal $v): BigDecimal
    {
        return $v->isPositive() ? $v : BigDecimal::zero();
    }
}
