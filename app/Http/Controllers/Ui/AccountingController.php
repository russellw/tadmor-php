<?php

namespace App\Http\Controllers\Ui;

use App\Http\Body;
use App\Services\Banking;
use App\Services\Calendar;
use App\Services\Master;
use App\Services\Posting;
use App\Services\Stock;
use App\Services\YearEnd;
use App\Support\Dates;
use App\Support\Dec;
use App\Ui\Choices as Ch;
use App\Ui\Column;
use App\Ui\Field;
use App\Ui\Ui;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Stock movements (§13.7, S1–S3) and accounting: periods, year-end, rates, bank statements (§13.9, A1–A5). */
class AccountingController
{
    private static function id(string $id): int
    {
        if (! ctype_digit($id) || strlen($id) > 9) {
            abort(404);
        }

        return (int) $id;
    }

    // ------------------------------------------------------------------
    // S1–S3 Stock movements
    // ------------------------------------------------------------------

    private static function movementFields(): array
    {
        return [
            new Field('product_id', 'Product', 'ref', Ch::products(['track_inventory' => true]), required: true,
                help: 'Only active, inventory-tracked products.'),
            new Field('warehouse_id', 'Warehouse', 'ref', Ch::warehouses(), required: true),
            new Field('movement_type', 'Type', 'select', Ch::movementTypes(), required: true),
            new Field('movement_date', 'Date', 'date'),
            new Field('quantity', 'Quantity', 'decimal', required: true,
                help: 'Enter the amount moved; receipts add stock and issues remove it. An adjustment keeps the sign you type.'),
            new Field('unit_cost', 'Unit cost', 'decimal'),
            new Field('reference', 'Reference'),
            new Field('notes', 'Notes', 'textarea'),
        ];
    }

    /** S2: the quantity is entered as a magnitude and signed by the type. */
    private static function signed(Body $b): Body
    {
        $type = $b->raw('movement_type');
        $q = $b->raw('quantity');
        $negative = in_array($type, ['issue', 'transfer_out'], true);
        if (is_string($q) && in_array($type, ['receipt', 'transfer_in', 'issue', 'transfer_out'], true)
            && preg_match('/^[+-]?(\d+(\.\d*)?|\.\d+)$/', trim($q))) {
            $magnitude = BigDecimal::of(ltrim(trim($q), '+'))->abs();

            return $b->with(['quantity' => ($negative ? $magnitude->negated() : $magnitude)->toString()]);
        }

        return $b;
    }

    public function movements()
    {
        $products = DB::table('products')->pluck('sku', 'id');
        $warehouses = DB::table('warehouses')->pluck('code', 'id');
        $rows = array_map(fn ($r) => $r + ['product' => $products[$r['product_id']] ?? null, 'warehouse' => $warehouses[$r['warehouse_id']] ?? null],
            Stock::all());

        return Ui::listPage('Stock movements', $rows, [
            new Column('Date', 'movement_date'), new Column('Product', 'product'), new Column('Warehouse', 'warehouse'),
            new Column('Type', 'movement_type', kind: 'status'), new Column('Quantity', 'quantity', true, 'qty'),
            new Column('Unit cost', 'unit_cost', true, 'amount'), new Column('Total cost', 'total_cost', true, 'amount'),
            new Column('Posted', fn ($r) => $r['status'] === 'posted', kind: 'bool'),
        ], fn ($r) => "/stock-movements/{$r['id']}", ['/stock-movements/new', 'New stock movement']);
    }

    public function newMovement(Request $request)
    {
        return Ui::crudForm($request, 'New stock movement', self::movementFields(), ['movement_date' => Dates::today()],
            fn (Body $b) => Stock::create(self::signed($b), Ui::user($request)->id), fn ($id) => "/stock-movements/$id", '/stock-movements');
    }

    public function editMovement(Request $request, string $id)
    {
        $id = self::id($id);
        $m = Stock::json(Stock::get($id));
        if ($m['movement_type'] !== 'adjustment') {
            $m['quantity'] = Dec::of($m['quantity'])->abs()->toString();
        }

        return Ui::crudForm($request, "Edit stock movement $id", self::movementFields(), $m,
            fn (Body $b) => Stock::update($id, self::signed($b)), fn () => "/stock-movements/$id", "/stock-movements/$id");
    }

    private static function movementDetail(Request $request, int $id, array $errors = [])
    {
        $m = Stock::json(Stock::get($id));

        return view('movement_detail', [
            'title' => "Stock movement $id", 'm' => $m, 'errors' => $errors,
            'product' => Master::product($m['product_id']), 'warehouse' => Master::warehouse($m['warehouse_id']),
            'creditChoices' => (Ch::postableAccounts())(),
            'defaultCredit' => $request->input('credit_account_id') ?? DB::table('accounts')->where('code', '2150')->value('id'),
        ]);
    }

    public function showMovement(Request $request, string $id)
    {
        return self::movementDetail($request, self::id($id));
    }

    public function movementAction(Request $request, string $id)
    {
        $id = self::id($id);
        $action = $request->route('action');
        if (! $request->isMethod('post')) {
            return redirect("/stock-movements/$id");
        }
        if ($action === 'unpost' && ! Ui::user($request)->is_admin) {
            return self::movementDetail($request, $id, [$action => 'Only administrators can do this.']);
        }
        $credit = (string) $request->input('credit_account_id', '');
        [, $error] = Ui::attempt(fn () => $action === 'post'
            ? Posting::postMovement($id, ctype_digit($credit) && strlen($credit) <= 9 ? (int) $credit : null)
            : Posting::unpostMovement($id));

        return $error ? self::movementDetail($request, $id, [$action => $error]) : redirect("/stock-movements/$id");
    }

    public function deleteMovement(Request $request, string $id)
    {
        $id = self::id($id);
        Stock::get($id);

        return self::confirm($request, "Delete stock movement $id?",
            'This deletes the unposted movement. A movement made by order fulfilment returns its quantity to the order.',
            "/stock-movements/$id", '/stock-movements', fn () => Stock::delete($id));
    }

    private static function confirm(Request $request, string $title, string $question, string $back, string $done, \Closure $fn)
    {
        $error = null;
        if ($request->isMethod('post')) {
            [, $error] = Ui::attempt($fn);
            if ($error === null) {
                return redirect($done);
            }
        }

        return view('confirm', compact('title', 'question', 'back', 'error'));
    }

    // ------------------------------------------------------------------
    // A1 Fiscal years and periods, A2 year-end
    // ------------------------------------------------------------------

    private static function periodsPage(array $errors = [])
    {
        $periods = DB::table('accounting_periods')->orderBy('start_date')->get();
        $years = DB::table('fiscal_years')->orderBy('start_date')->get();
        $open = $years->where('status', 'open');
        $closed = $years->where('status', 'closed');

        return view('periods', [
            'title' => 'Periods and year-end', 'errors' => $errors,
            'years' => $years->map(fn ($y) => ['year' => $y, 'periods' => $periods->where('fiscal_year_id', $y->id)->values()]),
            'closable' => $open->first(), 'reopenable' => $closed->last(),
        ]);
    }

    public function periods()
    {
        return self::periodsPage();
    }

    /** A1: close or reopen a period in one step. */
    public function togglePeriod(Request $request, string $id)
    {
        $id = self::id($id);
        if ($request->isMethod('post')) {
            $p = Calendar::periodJson(Calendar::period($id));
            $p['status'] = $p['status'] === 'open' ? 'closed' : 'open';
            [, $error] = Ui::attempt(fn () => Calendar::updatePeriod($id, Body::of((object) $p)));
            if ($error) {
                return self::periodsPage(["period-$id" => $error]);
            }
        }

        return redirect('/periods');
    }

    private static function yearFields(): array
    {
        return [new Field('name', 'Name', required: true), new Field('start_date', 'Start date', 'date', required: true),
            new Field('end_date', 'End date', 'date', required: true)];
    }

    public function newYear(Request $request)
    {
        $last = DB::table('fiscal_years')->orderByDesc('end_date')->first();
        $initial = [];
        if ($last) {
            $start = Dates::addDays($last->end_date, 1);
            $end = Dates::addDays(Dates::plusYear($start), -1);
            $initial = ['name' => 'FY'.substr($end, 0, 4), 'start_date' => $start, 'end_date' => $end];
        }

        return Ui::crudForm($request, 'New fiscal year', self::yearFields(), $initial, Calendar::createFiscalYear(...),
            fn () => '/periods', '/periods');
    }

    public function editYear(Request $request, string $id)
    {
        $id = self::id($id);
        $y = Calendar::fiscalYearJson(Calendar::fiscalYear($id));

        return Ui::crudForm($request, "Edit fiscal year {$y['name']}", self::yearFields(), $y,
            fn (Body $b) => Calendar::updateFiscalYear($id, $b), fn () => '/periods', '/periods');
    }

    private static function periodFields(): array
    {
        return [new Field('fiscal_year_id', 'Fiscal year', 'ref', Ch::fiscalYears(), required: true),
            new Field('name', 'Name', required: true), new Field('start_date', 'Start date', 'date', required: true),
            new Field('end_date', 'End date', 'date', required: true)];
    }

    public function newPeriod(Request $request)
    {
        return Ui::crudForm($request, 'New accounting period', self::periodFields(), Calendar::nextPeriodProposal() ?? [],
            Calendar::createPeriod(...), fn () => '/periods', '/periods');
    }

    public function editPeriod(Request $request, string $id)
    {
        $id = self::id($id);
        $p = Calendar::periodJson(Calendar::period($id));

        return Ui::crudForm($request, "Edit period {$p['name']}",
            [...self::periodFields(), new Field('status', 'Status', 'select', Ch::words(['open', 'closed']))], $p,
            fn (Body $b) => Calendar::updatePeriod($id, $b), fn () => '/periods', '/periods');
    }

    /** A2: close a year, after saying what will happen. */
    public function closeYear(Request $request, string $id)
    {
        $id = self::id($id);
        $y = Calendar::fiscalYear($id);
        $chosen = $request->input('retained_earnings_account_id') ?? DB::table('accounts')->where('code', '3000')->value('id');
        $error = null;
        if ($request->isMethod('post')) {
            $raw = (string) $request->input('retained_earnings_account_id', '');
            [, $error] = Ui::attempt(fn () => YearEnd::close($id,
                Body::of((object) ['retained_earnings_account_id' => ctype_digit($raw) && strlen($raw) <= 9 ? (int) $raw : null])
                    ->requiredId('retained_earnings_account_id')));
            if ($error === null) {
                return redirect('/periods');
            }
        }

        return view('year_close', ['title' => "Close fiscal year {$y->name}", 'year' => $y, 'error' => $error, 'chosen' => $chosen,
            'equityAccounts' => (Ch::accounts(['is_postable' => true, 'account_type' => 'equity']))()]);
    }

    public function reopenYear(Request $request, string $id)
    {
        $id = self::id($id);
        if ($request->isMethod('post')) {
            [, $error] = Ui::attempt(fn () => YearEnd::reopen($id));
            if ($error) {
                return self::periodsPage(['year' => $error]);
            }
        }

        return redirect('/periods');
    }

    // ------------------------------------------------------------------
    // A3 Exchange rates
    // ------------------------------------------------------------------

    private static function rateFields(): array
    {
        return [
            new Field('currency_code', 'Currency', 'select', Ch::currencies(), required: true, readonlyOnEdit: true),
            new Field('rate_date', 'Date', 'date', required: true, readonlyOnEdit: true),
            new Field('rate', 'Rate', 'decimal', required: true, help: 'Base-currency units bought by one unit of this currency.'),
        ];
    }

    public function rates()
    {
        return Ui::listPage('Exchange rates', Master::exchangeRates(), [
            new Column('Currency', 'currency_code'), new Column('Date', 'rate_date'), new Column('Rate', 'rate', true),
        ], fn ($r) => "/exchange-rates/{$r['currency_code']}/{$r['rate_date']}", ['/exchange-rates/new', 'New rate'],
            'No exchange rates. Documents in '.Master::baseCurrency().' need none.');
    }

    public function newRate(Request $request)
    {
        return Ui::crudForm($request, 'New exchange rate', self::rateFields(), [], Master::createExchangeRate(...),
            fn () => '/exchange-rates', '/exchange-rates');
    }

    private static function rate(string $currency, string $date): array
    {
        foreach (Master::exchangeRates() as $r) {
            if ($r['currency_code'] === $currency && $r['rate_date'] === $date) {
                return $r;
            }
        }
        abort(404);
    }

    public function editRate(Request $request, string $currency, string $date)
    {
        $r = self::rate($currency, $date);

        return Ui::crudForm($request, "$currency rate on $date", self::rateFields(), $r,
            fn (Body $b) => Master::updateExchangeRate($currency, $date, $b), fn () => '/exchange-rates', '/exchange-rates',
            editing: true, extra: ['links' => [["/exchange-rates/$currency/$date/delete", 'Delete this rate']]]);
    }

    public function deleteRate(Request $request, string $currency, string $date)
    {
        self::rate($currency, $date);

        return self::confirm($request, "Delete the $currency rate for $date?", 'Entries already posted keep the rate they used.',
            "/exchange-rates/$currency/$date", '/exchange-rates', fn () => Master::deleteExchangeRate($currency, $date));
    }

    // ------------------------------------------------------------------
    // A4 and A5 Bank statements
    // ------------------------------------------------------------------

    private static function statementFields(): array
    {
        return [
            new Field('account_id', 'Cash account', 'ref', Ch::accounts(['is_postable' => true, 'is_cash' => true]), required: true),
            new Field('statement_date', 'Statement date', 'date', required: true),
            new Field('opening_balance', 'Opening balance', 'decimal', required: true),
            new Field('closing_balance', 'Closing balance', 'decimal', required: true),
            new Field('reference', 'Reference'),
        ];
    }

    public function statements()
    {
        return Ui::listPage('Bank statements', Banking::all(), [
            new Column('Date', 'statement_date'), new Column('Account', fn ($r) => "{$r['account_code']} {$r['account_name']}"),
            new Column('Reference', 'reference'), new Column('Closing balance', 'closing_balance', true, 'amount'),
            new Column('Matched', fn ($r) => "{$r['matched_count']} of {$r['line_count']}", true),
            new Column('Difference', 'difference', true, 'amount'), new Column('Status', 'status', kind: 'status'),
        ], fn ($r) => "/bank-statements/{$r['id']}", ['/bank-statements/new', 'New statement']);
    }

    public function newStatement(Request $request)
    {
        return Ui::crudForm($request, 'New bank statement', self::statementFields(), [],
            fn (Body $b) => Banking::create($b, Ui::user($request)->id), fn ($id) => "/bank-statements/$id", '/bank-statements');
    }

    public function editStatement(Request $request, string $id)
    {
        $id = self::id($id);
        $s = Banking::json(Banking::get($id));

        return Ui::crudForm($request, 'Edit bank statement', self::statementFields(), $s,
            fn (Body $b) => Banking::update($id, $b), fn () => "/bank-statements/$id", "/bank-statements/$id");
    }

    private static function statementPage(int $id, array $errors = [], array $values = [])
    {
        $s = Banking::json(Banking::get($id));
        $lines = Banking::lines($id);
        $candidates = $s['status'] === 'open' ? Banking::candidates($id) : [];
        foreach ($lines as &$line) {
            $line['error'] = $errors["line-{$line['id']}"] ?? null;
            $line['candidates'] = $line['journal_line_id'] === null
                ? array_values(array_filter($candidates, fn ($c) => Dec::of($c['amount'])->compareTo($line['amount']) === 0))
                : [];
        }
        unset($line);

        return view('statement_detail', ['title' => 'Bank statement '.($s['reference'] ?? $s['id']), 's' => $s, 'lines' => $lines,
            'allCandidates' => $candidates, 'errors' => $errors, 'values' => $values]);
    }

    public function showStatement(string $id)
    {
        return self::statementPage(self::id($id));
    }

    public function statementAction(Request $request, string $id)
    {
        $id = self::id($id);
        $action = $request->route('action');
        if (! $request->isMethod('post')) {
            return redirect("/bank-statements/$id");
        }
        if ($action === 'reopen' && ! Ui::user($request)->is_admin) {
            return self::statementPage($id, [$action => 'Only administrators can do this.']);
        }
        $text = fn ($k) => trim((string) $request->input($k, '')) ?: null;
        [, $error] = Ui::attempt(fn () => match ($action) {
            'add' => Banking::addLine($id, Body::of((object) ['txn_date' => $text('txn_date'), 'description' => $text('description'),
                'reference' => $text('reference'), 'amount' => $text('amount')])),
            'import' => Banking::importCsv($id, Body::of((object) ['csv' => $request->input('csv') ?: null])),
            'auto' => Banking::autoMatch($id),
            'reconcile' => Banking::reconcile($id),
            'reopen' => Banking::reopen($id),
        });

        return $error ? self::statementPage($id, [$action => $error], $request->only(['txn_date', 'description', 'reference', 'amount', 'csv']))
            : redirect("/bank-statements/$id");
    }

    public function lineAction(Request $request, string $id, string $line)
    {
        $id = self::id($id);
        $line = self::id($line);
        $action = $request->route('action');
        if (! $request->isMethod('post')) {
            return redirect("/bank-statements/$id");
        }
        $raw = (string) $request->input('journal_line_id', '');
        [, $error] = Ui::attempt(fn () => match ($action) {
            'match' => Banking::match($line, Body::of((object) ['journal_line_id' => ctype_digit($raw) && strlen($raw) <= 9 ? (int) $raw : null])),
            'unmatch' => Banking::unmatch($line),
            'delete' => Banking::deleteLine($line),
        });

        return $error ? self::statementPage($id, ["line-$line" => $error]) : redirect("/bank-statements/$id");
    }

    public function deleteStatement(Request $request, string $id)
    {
        $id = self::id($id);
        $s = Banking::json(Banking::get($id));

        return self::confirm($request, 'Delete bank statement '.($s['reference'] ?? $s['id']).'?', 'This deletes the statement and all its lines.',
            "/bank-statements/$id", '/bank-statements', fn () => Banking::delete($id));
    }
}
