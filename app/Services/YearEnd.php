<?php

namespace App\Services;

use App\Errors\ApiError;
use App\Support\Dates;
use App\Support\Dec;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/** Year-end close and reopen (domain §9.3). */
final class YearEnd
{
    private static function year(int $id): object
    {
        return DB::table('fiscal_years')->where('id', $id)->lockForUpdate()->first()
            ?? throw new ApiError(404, 'fiscal year not found');
    }

    /** Each revenue and expense account's non-zero base balance (debit-positive) up to a date. */
    private static function incomeBalances(string $endDate): array
    {
        return DB::select(
            "SELECT jl.account_id, sum(jl.base_debit - jl.base_credit) AS balance
             FROM journal_lines jl
             JOIN journal_entries je ON je.id = jl.journal_entry_id
             JOIN accounts a ON a.id = jl.account_id
             WHERE je.status = 'posted' AND a.account_type IN ('revenue', 'expense') AND je.entry_date <= ?
             GROUP BY jl.account_id HAVING sum(jl.base_debit - jl.base_credit) <> 0
             ORDER BY jl.account_id",
            [$endDate],
        );
    }

    public static function close(int $id, int $retainedEarningsId): array
    {
        $year = self::year($id);
        if ($year->status !== 'open') {
            throw new ApiError(409, "fiscal year {$year->name} is not open");
        }
        if (DB::table('fiscal_years')->where('status', 'open')->where('start_date', '<', $year->start_date)
            ->where('id', '<>', $id)->exists()) {
            throw new ApiError(422, 'an earlier fiscal year is still open');
        }
        if (! Master::isPostable($retainedEarningsId, ['account_type' => 'equity'])) {
            throw new ApiError(422, 'the retained earnings account must be a postable, active equity account');
        }

        $closingId = null;
        $balances = self::incomeBalances($year->end_date);
        if ($balances) {
            $period = DB::table('accounting_periods')->where('start_date', '<=', $year->end_date)
                ->where('end_date', '>=', $year->end_date)->first();
            if ($period === null) {
                $period = Calendar::periodForPosting($year->end_date);
            } elseif ($period->status === 'closed') {
                DB::table('accounting_periods')->where('id', $period->id)->update(['status' => 'open']);
            }
            $lines = [];
            $net = BigDecimal::zero(); // debit-positive: a net loss is positive
            foreach ($balances as $row) {
                $bal = Dec::of($row->balance);
                $lines[] = [$row->account_id, Dec::pos($bal->negated()), Dec::pos($bal), Dec::pos($bal->negated()), Dec::pos($bal), 'Year-end close'];
                $net = $net->plus($bal);
            }
            if (! $net->isZero()) {
                $lines[] = [$retainedEarningsId, Dec::pos($net), Dec::pos($net->negated()), Dec::pos($net), Dec::pos($net->negated()),
                    "Net income (loss) for {$year->name}"];
            }
            $closingId = Posting::newEntry($year->end_date, Master::baseCurrency(), $lines, memo: "Year-end close {$year->name}",
                reference: $year->name, rate: BigDecimal::one(), isClosing: true, period: $period);
        }

        DB::table('accounting_periods')->where('fiscal_year_id', $id)->where('status', 'open')->update(['status' => 'closed']);
        DB::table('fiscal_years')->where('id', $id)->update(['status' => 'closed', 'closing_entry_id' => $closingId]);

        $nextId = null;
        $start = Dates::addDays($year->end_date, 1);
        $end = Dates::addDays(Dates::plusYear($start), -1);
        $name = 'FY'.substr($end, 0, 4);
        if (! DB::table('fiscal_years')->where('start_date', '<=', $start)->where('end_date', '>=', $start)->exists()
            && ! DB::table('fiscal_years')->where('name', $name)->exists()) {
            $nextId = DB::table('fiscal_years')->insertGetId(['name' => $name, 'start_date' => $start, 'end_date' => $end]);
        }

        return ['closing_entry_id' => $closingId, 'next_fiscal_year_id' => $nextId];
    }

    public static function reopen(int $id): ?int
    {
        $year = self::year($id);
        if ($year->status !== 'closed') {
            throw new ApiError(409, "fiscal year {$year->name} is not closed");
        }
        if (DB::table('fiscal_years')->where('status', 'closed')->where('start_date', '>', $year->start_date)->exists()) {
            throw new ApiError(422, 'a later fiscal year is closed');
        }
        DB::table('fiscal_years')->where('id', $id)->update(['status' => 'open', 'closing_entry_id' => null]);
        if ($year->closing_entry_id === null) {
            return null;
        }
        $periodId = DB::table('journal_entries')->where('id', $year->closing_entry_id)->value('period_id');
        DB::table('accounting_periods')->where('id', $periodId)->where('status', 'closed')->update(['status' => 'open']);

        return Posting::reverseEntry($year->closing_entry_id);
    }
}
