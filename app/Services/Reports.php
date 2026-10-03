<?php

namespace App\Services;

use App\Errors\ApiError;
use App\Support\Dec;
use Illuminate\Support\Facades\DB;

/**
 * Journal and reports (spec/api.md §5.14, domain §10).
 *
 * Every figure is a sum of base amounts over posted journal lines. Reports
 * are aggregate queries, so they are written in SQL over the shared tables
 * and views. Optional date bounds are bound twice: once to test for null.
 */
final class Reports
{
    private const POSTED = "FROM journal_lines jl
        JOIN journal_entries je ON je.id = jl.journal_entry_id
        JOIN accounts a ON a.id = jl.account_id
        WHERE je.status = 'posted'";

    private const RANGE = 'AND (CAST(? AS date) IS NULL OR je.entry_date >= CAST(? AS date))
        AND (CAST(? AS date) IS NULL OR je.entry_date <= CAST(? AS date))';

    /** Format the named money columns of each row; rows become arrays. */
    private static function money(array $rows, string ...$fields): array
    {
        return array_map(function ($r) use ($fields) {
            $r = (array) $r;
            foreach ($fields as $f) {
                $r[$f] = Dec::fmt4($r[$f]);
            }

            return $r;
        }, $rows);
    }

    public static function journalEntry(int $id): array
    {
        $e = DB::table('journal_entries')->where('id', $id)->first() ?? throw new ApiError(404, 'journal entry not found');
        $lines = DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('l.journal_entry_id', $id)->orderBy('l.line_no')
            ->get(['l.*', 'a.code as account_code', 'a.name as account_name']);

        return [
            'id' => $e->id, 'entry_date' => $e->entry_date, 'currency_code' => $e->currency_code,
            'exchange_rate' => Dec::fmtRate($e->exchange_rate), 'reference' => $e->reference, 'memo' => $e->memo,
            'status' => $e->status, 'is_closing' => $e->is_closing, 'reverses_entry_id' => $e->reverses_entry_id,
            'lines' => $lines->map(fn ($l) => [
                'line_no' => $l->line_no, 'account_id' => $l->account_id, 'account_code' => $l->account_code,
                'account_name' => $l->account_name, 'memo' => $l->memo, 'debit' => Dec::fmt4($l->debit),
                'credit' => Dec::fmt4($l->credit), 'base_debit' => Dec::fmt4($l->base_debit),
                'base_credit' => Dec::fmt4($l->base_credit),
            ])->all(),
        ];
    }

    public static function ledger(int $accountId, ?string $from, ?string $to): array
    {
        Master::account($accountId);
        $rows = DB::select(
            "SELECT je.id AS journal_entry_id, je.entry_date, je.reference, COALESCE(jl.memo, je.memo) AS memo,
                    je.currency_code, jl.debit, jl.credit, jl.base_debit, jl.base_credit
             FROM journal_lines jl JOIN journal_entries je ON je.id = jl.journal_entry_id
             WHERE jl.account_id = ? AND je.status = 'posted' ".self::RANGE.'
             ORDER BY je.entry_date, je.id, jl.line_no',
            [$accountId, $from, $from, $to, $to],
        );

        return self::money($rows, 'debit', 'credit', 'base_debit', 'base_credit');
    }

    public static function trialBalance(): array
    {
        $rows = DB::select('SELECT account_id, code, name, account_type, total_debit, total_credit, balance FROM trial_balance ORDER BY code');

        return self::money($rows, 'total_debit', 'total_credit', 'balance');
    }

    public static function profitAndLoss(?string $from, ?string $to): array
    {
        $rows = DB::select(
            "SELECT a.id AS account_id, a.code, a.name, a.account_type,
                    sum(CASE WHEN a.account_type = 'revenue' THEN jl.base_credit - jl.base_debit
                             ELSE jl.base_debit - jl.base_credit END) AS amount
             ".self::POSTED." AND NOT je.is_closing AND a.account_type IN ('revenue', 'expense') ".self::RANGE.'
             GROUP BY a.id ORDER BY a.code',
            [$from, $from, $to, $to],
        );

        return self::money($rows, 'amount');
    }

    public static function balanceSheet(?string $asOf): array
    {
        $upTo = 'AND (CAST(? AS date) IS NULL OR je.entry_date <= CAST(? AS date))';
        $rows = DB::select(
            "SELECT a.id AS account_id, a.code, a.name, a.account_type,
                    sum(CASE WHEN a.account_type = 'asset' THEN jl.base_debit - jl.base_credit
                             ELSE jl.base_credit - jl.base_debit END) AS amount
             ".self::POSTED." AND a.account_type IN ('asset', 'liability', 'equity') $upTo
             GROUP BY a.id ORDER BY a.code",
            [$asOf, $asOf],
        );
        $earnings = DB::selectOne(
            'SELECT COALESCE(sum(jl.base_credit - jl.base_debit), 0) AS v '.self::POSTED." AND a.account_type IN ('revenue', 'expense') $upTo",
            [$asOf, $asOf],
        )->v;

        return ['rows' => self::money($rows, 'amount'), 'current_earnings' => Dec::fmt4($earnings)];
    }

    public static function cashFlow(?string $from, ?string $to): array
    {
        $range = [$from, $from, $to, $to];
        $netIncome = DB::selectOne(
            'SELECT COALESCE(sum(jl.base_credit - jl.base_debit), 0) AS v '.self::POSTED
            ." AND NOT je.is_closing AND a.account_type IN ('revenue', 'expense') ".self::RANGE,
            $range,
        )->v;
        $rows = DB::select(
            'SELECT a.id AS account_id, a.code, a.name, a.cash_flow_activity AS activity,
                    sum(jl.base_credit - jl.base_debit) AS amount '.self::POSTED."
               AND NOT je.is_closing AND NOT a.is_cash AND a.account_type IN ('asset', 'liability', 'equity') ".self::RANGE.'
             GROUP BY a.id ORDER BY a.code',
            $range,
        );
        $cash = DB::selectOne(
            'SELECT COALESCE(sum(jl.base_debit - jl.base_credit)
                        FILTER (WHERE CAST(? AS date) IS NOT NULL AND je.entry_date < CAST(? AS date)), 0) AS opening,
                    COALESCE(sum(jl.base_debit - jl.base_credit)
                        FILTER (WHERE (CAST(? AS date) IS NULL OR je.entry_date >= CAST(? AS date))
                                  AND (CAST(? AS date) IS NULL OR je.entry_date <= CAST(? AS date))), 0) AS movement,
                    COALESCE(sum(jl.base_debit - jl.base_credit)
                        FILTER (WHERE CAST(? AS date) IS NULL OR je.entry_date <= CAST(? AS date)), 0) AS closing
             '.self::POSTED.' AND a.is_cash',
            [$from, $from, $from, $from, $to, $to, $to, $to],
        );

        return [
            'net_income' => Dec::fmt4($netIncome), 'rows' => self::money($rows, 'amount'),
            'net_cash_flow' => Dec::fmt4($cash->movement), 'opening_cash' => Dec::fmt4($cash->opening),
            'closing_cash' => Dec::fmt4($cash->closing),
        ];
    }

    public static function aging(bool $sales): array
    {
        [$view, $party, $table] = $sales ? ['ar_aging', 'customer_id', 'customers'] : ['ap_aging', 'supplier_id', 'suppliers'];
        $rows = DB::select(
            "SELECT g.$party AS party_id, o.name AS party_name, g.total_outstanding,
                    COALESCE(g.not_yet_due, 0) AS not_yet_due, COALESCE(g.days_1_30, 0) AS days_1_30,
                    COALESCE(g.days_31_60, 0) AS days_31_60, COALESCE(g.days_61_90, 0) AS days_61_90,
                    COALESCE(g.days_over_90, 0) AS days_over_90
             FROM $view g JOIN $table p ON p.id = g.$party JOIN organizations o ON o.id = p.organization_id
             ORDER BY g.$party",
        );

        return self::money($rows, 'total_outstanding', 'not_yet_due', 'days_1_30', 'days_31_60', 'days_61_90', 'days_over_90');
    }

    public static function inventoryValuation(): array
    {
        $rows = DB::select(
            'SELECT v.product_id, p.sku, p.name, v.qty_on_hand, v.value_on_hand, v.avg_unit_cost
             FROM stock_valuation v JOIN products p ON p.id = v.product_id ORDER BY p.sku, p.id',
        );

        return self::money($rows, 'qty_on_hand', 'value_on_hand', 'avg_unit_cost');
    }
}
