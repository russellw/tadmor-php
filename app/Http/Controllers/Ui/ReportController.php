<?php

namespace App\Http\Controllers\Ui;

use App\Errors\ApiError;
use App\Services\Master;
use App\Services\Reports;
use App\Support\Dates;
use App\Support\Dec;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;

/** Reports (domain §13.8, R1–R8). Every date bound is optional, and a blank one is unbounded. */
class ReportController
{
    private const FROM_TO = [['from', 'From'], ['to', 'To']];

    private const AS_OF = [['as_of', 'As of']];

    /** Optional YYYY-MM-DD query parameters, and a message if one is malformed. */
    private static function dates(Request $request, string ...$names): array
    {
        $out = [];
        $error = null;
        foreach ($names as $n) {
            $v = trim((string) $request->query($n, ''));
            try {
                $out[$n] = $v === '' ? null : Dates::parse($v, $n, 400);
            } catch (ApiError $e) {
                [$out[$n], $error] = [null, $e->getMessage()];
            }
        }

        return [$out, $error];
    }

    private static function sum(array $rows, string $key = 'amount'): BigDecimal
    {
        return array_reduce($rows, fn ($t, $r) => $t->plus($r[$key]), BigDecimal::zero());
    }

    private static function filters(Request $request, array $filters, ?string $error): array
    {
        return ['filters' => $filters, 'params' => $request->query(), 'error' => $error];
    }

    public function profitAndLoss(Request $request)
    {
        [$d, $error] = self::dates($request, 'from', 'to');
        $rows = Reports::profitAndLoss($d['from'], $d['to']);
        $revenue = array_values(array_filter($rows, fn ($r) => $r['account_type'] === 'revenue'));
        $expense = array_values(array_filter($rows, fn ($r) => $r['account_type'] === 'expense'));

        return view('report_pnl', ['title' => 'Profit and loss', 'revenue' => $revenue, 'expense' => $expense,
            'totalRevenue' => self::sum($revenue), 'totalExpense' => self::sum($expense),
            'netIncome' => self::sum($revenue)->minus(self::sum($expense))] + self::filters($request, self::FROM_TO, $error));
    }

    public function balanceSheet(Request $request)
    {
        [$d, $error] = self::dates($request, 'as_of');
        $bs = Reports::balanceSheet($d['as_of']);
        $sections = [];
        $totals = [];
        foreach (['asset', 'liability', 'equity'] as $t) {
            $sections[$t] = array_values(array_filter($bs['rows'], fn ($r) => $r['account_type'] === $t));
            $totals[$t] = self::sum($sections[$t]);
        }
        $earnings = Dec::of($bs['current_earnings']);

        return view('report_bs', ['title' => 'Balance sheet', 'sections' => $sections, 'totals' => $totals, 'earnings' => $earnings,
            'liabilitiesAndEquity' => $totals['liability']->plus($totals['equity'])->plus($earnings)]
            + self::filters($request, self::AS_OF, $error));
    }

    public function cashFlow(Request $request)
    {
        [$d, $error] = self::dates($request, 'from', 'to');
        $cf = Reports::cashFlow($d['from'], $d['to']);
        $sections = [];
        foreach (['operating', 'investing', 'financing'] as $activity) {
            $rows = array_values(array_filter($cf['rows'], fn ($r) => $r['activity'] === $activity));
            $subtotal = self::sum($rows)->plus($activity === 'operating' ? $cf['net_income'] : 0);
            $sections[] = ['name' => $activity, 'rows' => $rows, 'subtotal' => $subtotal];
        }

        return view('report_cf', ['title' => 'Cash flow', 'cf' => $cf, 'sections' => $sections] + self::filters($request, self::FROM_TO, $error));
    }

    public function trialBalance()
    {
        $rows = Reports::trialBalance();

        return view('report_tb', ['title' => 'Trial balance', 'rows' => $rows, 'totalDebit' => self::sum($rows, 'total_debit'),
            'totalCredit' => self::sum($rows, 'total_credit'), 'totalBalance' => self::sum($rows, 'balance')]);
    }

    public function ledger(Request $request, string $id)
    {
        if (! ctype_digit($id) || strlen($id) > 9) {
            abort(404);
        }
        $account = Master::account((int) $id);
        [$d, $error] = self::dates($request, 'from', 'to');
        $rows = Reports::ledger($account->id, $d['from'], $d['to']);
        $base = Master::baseCurrency();
        $running = BigDecimal::zero();
        if ($d['from']) { // the balance carried in from before the range
            foreach (Reports::ledger($account->id, null, Dates::addDays($d['from'], -1)) as $r) {
                $running = $running->plus($r['base_debit'])->minus($r['base_credit']);
            }
        }
        $opening = $running;
        foreach ($rows as &$r) {
            $running = $running->plus($r['base_debit'])->minus($r['base_credit']);
            $r['running'] = $running;
        }
        unset($r);

        return view('report_ledger', ['title' => "Ledger: {$account->code} {$account->name}", 'rows' => $rows, 'opening' => $opening,
            'closing' => $running, 'base' => $base, 'foreign' => (bool) array_filter($rows, fn ($r) => $r['currency_code'] !== $base)]
            + self::filters($request, self::FROM_TO, $error));
    }

    public function journalEntry(string $id)
    {
        if (! ctype_digit($id) || strlen($id) > 9) {
            abort(404);
        }
        $e = Reports::journalEntry((int) $id);
        $totals = [];
        foreach (['debit', 'credit', 'base_debit', 'base_credit'] as $k) {
            $totals[$k] = self::sum($e['lines'], $k);
        }

        return view('journal_entry', ['title' => "Journal entry $id", 'e' => $e, 'totals' => $totals]);
    }

    private static function aging(bool $sales)
    {
        $rows = Reports::aging($sales);
        $totals = [];
        foreach (['not_yet_due', 'days_1_30', 'days_31_60', 'days_61_90', 'days_over_90', 'total_outstanding'] as $k) {
            $totals[$k] = self::sum($rows, $k);
        }

        return view('report_aging', ['title' => $sales ? 'AR aging' : 'AP aging', 'rows' => $rows, 'sales' => $sales, 'totals' => $totals]);
    }

    public function arAging()
    {
        return self::aging(true);
    }

    public function apAging()
    {
        return self::aging(false);
    }

    public function inventoryValuation()
    {
        $rows = Reports::inventoryValuation();

        return view('report_valuation', ['title' => 'Inventory valuation', 'rows' => $rows, 'total' => self::sum($rows, 'value_on_hand')]);
    }
}
