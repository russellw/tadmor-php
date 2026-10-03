<?php

namespace App\Http\Controllers\Api;

use App\Http\Api;
use App\Http\Body;
use App\Services\Master;
use App\Services\Reports;
use App\Services\YearEnd;
use Illuminate\Http\Request;

/** Settings, exchange rates, year-end, the journal, and reports (spec/api.md §5.7, §5.8, §5.14). */
class LedgerController
{
    public function settings()
    {
        return Api::ok(Master::settingsJson(Master::settings()));
    }

    public function updateSettings(Request $request)
    {
        Master::updateSettings(Body::from($request));

        return Api::noContent();
    }

    public function exchangeRates()
    {
        return Api::ok(Master::exchangeRates());
    }

    public function createExchangeRate(Request $request)
    {
        return Api::created(Master::createExchangeRate(Body::from($request)));
    }

    public function updateExchangeRate(Request $request, string $currency, string $date)
    {
        Master::updateExchangeRate($currency, $date, Body::from($request));

        return Api::noContent();
    }

    public function deleteExchangeRate(string $currency, string $date)
    {
        Master::deleteExchangeRate($currency, $date);

        return Api::noContent();
    }

    public function closeYear(Request $request, string $id)
    {
        $id = Api::id($id);
        $account = Body::from($request)->requiredId('retained_earnings_account_id');

        return Api::ok(YearEnd::close($id, $account));
    }

    public function reopenYear(string $id)
    {
        return Api::ok(['reversal_entry_id' => YearEnd::reopen(Api::id($id))]);
    }

    public function journalEntry(string $id)
    {
        return Api::ok(Reports::journalEntry(Api::id($id)));
    }

    public function ledger(Request $request, string $id)
    {
        $id = Api::id($id);

        return Api::ok(Reports::ledger($id, Api::dateParam($request, 'from'), Api::dateParam($request, 'to')));
    }

    public function trialBalance()
    {
        return Api::ok(Reports::trialBalance());
    }

    public function profitAndLoss(Request $request)
    {
        return Api::ok(Reports::profitAndLoss(Api::dateParam($request, 'from'), Api::dateParam($request, 'to')));
    }

    public function balanceSheet(Request $request)
    {
        return Api::ok(Reports::balanceSheet(Api::dateParam($request, 'as_of')));
    }

    public function cashFlow(Request $request)
    {
        return Api::ok(Reports::cashFlow(Api::dateParam($request, 'from'), Api::dateParam($request, 'to')));
    }

    public function arAging()
    {
        return Api::ok(Reports::aging(sales: true));
    }

    public function apAging()
    {
        return Api::ok(Reports::aging(sales: false));
    }

    public function inventoryValuation()
    {
        return Api::ok(Reports::inventoryValuation());
    }
}
