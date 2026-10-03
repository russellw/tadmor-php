<?php

namespace App\Ui;

use App\Services\Documents;
use App\Services\Master;
use Closure;
use Illuminate\Support\Facades\DB;

/** Pickers over active records (domain §13: "a picker over active records for each reference"). */
final class Choices
{
    public static function accounts(array $where = []): Closure
    {
        return fn () => DB::table('accounts')->where(['is_active' => true] + $where)->orderBy('code')->get()
            ->map(fn ($a) => [$a->id, "{$a->code} {$a->name}"])->all();
    }

    public static function postableAccounts(): Closure
    {
        return self::accounts(['is_postable' => true]);
    }

    public static function currencies(): Closure
    {
        return fn () => DB::table('currencies')->orderBy('code')->get()->map(fn ($c) => [$c->code, "{$c->code} {$c->name}"])->all();
    }

    public static function countries(): Closure
    {
        return fn () => DB::table('countries')->orderBy('code')->get()->map(fn ($c) => [$c->code, "{$c->code} {$c->name}"])->all();
    }

    public static function organizations(): Closure
    {
        return fn () => DB::table('organizations')->orderBy('name')->get()->map(fn ($o) => [$o->id, $o->name])->all();
    }

    /** Active customers or suppliers, by organization name. */
    public static function parties(bool $sales): Closure
    {
        [$table, $number] = $sales ? ['customers', 'customer_number'] : ['suppliers', 'supplier_number'];

        return fn () => DB::table("$table as p")->join('organizations as o', 'o.id', '=', 'p.organization_id')
            ->where('p.is_active', true)->orderBy('o.name')->get(['p.id', 'p.'.$number.' as number', 'o.name'])
            ->map(fn ($p) => [$p->id, $p->name.($p->number ? " ({$p->number})" : '')])->all();
    }

    public static function taxCodes(): Closure
    {
        return fn () => DB::table('tax_codes')->where('is_active', true)->orderBy('code')->get()
            ->map(fn ($t) => [$t->code, "{$t->code} {$t->name}"])->all();
    }

    public static function paymentTerms(): Closure
    {
        return fn () => DB::table('payment_terms')->orderBy('due_days')->orderBy('code')->get()
            ->map(fn ($p) => [$p->code, $p->name])->all();
    }

    public static function products(array $where = []): Closure
    {
        return fn () => DB::table('products')->where(['is_active' => true] + $where)->orderBy('sku')->get()
            ->map(fn ($p) => [$p->id, "{$p->sku} {$p->name}"])->all();
    }

    public static function warehouses(): Closure
    {
        return fn () => DB::table('warehouses')->where('is_active', true)->orderBy('code')->get()
            ->map(fn ($w) => [$w->id, "{$w->code} {$w->name}"])->all();
    }

    public static function fiscalYears(): Closure
    {
        return fn () => DB::table('fiscal_years')->orderBy('start_date')->get()->map(fn ($y) => [$y->id, $y->name])->all();
    }

    /** @param list<array{string, string}> $pairs */
    public static function fixed(array $pairs): Closure
    {
        return fn () => $pairs;
    }

    /** [[word, Word], ...] for a list of code words. */
    public static function words(array $words): Closure
    {
        return fn () => array_map(fn ($w) => [$w, Fmt::label($w)], $words);
    }

    public static function paymentMethods(): Closure
    {
        return self::words(Documents::PAYMENT_METHODS);
    }

    public static function accountTypes(): Closure
    {
        return self::words(Master::ACCOUNT_TYPES);
    }

    public static function activities(): Closure
    {
        return self::words(Master::ACTIVITIES);
    }

    public static function movementTypes(): Closure
    {
        return self::fixed([['receipt', 'Receipt (in)'], ['issue', 'Issue (out)'], ['adjustment', 'Adjustment (signed as typed)'],
            ['transfer_in', 'Transfer in'], ['transfer_out', 'Transfer out']]);
    }
}
