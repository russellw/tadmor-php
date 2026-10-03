<?php

namespace App\Ui;

use Brick\Math\BigDecimal;

/** Display formatting for the UI: exact amounts (domain §13 G7), quantities, and labels. */
final class Fmt
{
    /** An exact decimal, grouped, with at least two places: "1,234.50", "10.0011". */
    public static function amount(BigDecimal|string|int|null $v): string
    {
        if ($v === null || $v === '') {
            return '';
        }
        $s = BigDecimal::of($v)->toString();
        $sign = str_starts_with($s, '-') ? '-' : '';
        [$whole, $frac] = array_pad(explode('.', ltrim($s, '-'), 2), 2, '');
        $frac = str_pad(rtrim($frac, '0'), 2, '0');

        return $sign.number_format((int) $whole).'.'.$frac;
    }

    /** A quantity or rate without trailing zeros: "1.5", "3". */
    public static function qty(BigDecimal|string|int|null $v): string
    {
        if ($v === null || $v === '') {
            return '';
        }
        $s = BigDecimal::of($v)->toString();

        return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
    }

    public static function isNegative(BigDecimal|string|null $v): bool
    {
        return $v !== null && $v !== '' && BigDecimal::of($v)->isNegative();
    }

    /** A status or code word made readable: "partial" -> "Partial", "transfer_in" -> "Transfer in". */
    public static function label(?string $v): string
    {
        return $v ? ucfirst(str_replace('_', ' ', $v)) : '';
    }
}
