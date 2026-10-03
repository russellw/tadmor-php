<?php

namespace Tests\Feature;

use App\Services\Kinds;
use App\Services\Posting;
use App\Services\Sessions;
use App\Services\Users;
use App\Support\Dates;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Walk the server-rendered UI through the checklist of spec/domain.md §13.
 *
 * These drive the HTML pages: every screen renders, forms save through the
 * service layer, and refusals come back as the server's message next to
 * the action. The JSON API itself is covered by the conformance suite.
 */
class UiTest extends TestCase
{
    private array $acct;

    private int $customer;

    private int $product;

    private int $warehouse;

    private int $adminId;

    private int $clerkId;

    private ?string $session = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminId = Users::addOrReset('admin@example.com', 'Ada Admin', 'admin-pass', true)->id;
        $this->clerkId = Users::addOrReset('clerk@example.com', 'Carl Clerk', 'clerk-pass', false)->id;
        $this->acct = DB::table('accounts')->pluck('id', 'code')->all();
        $year = substr(Dates::today(), 0, 4);
        DB::table('fiscal_years')->insert(['name' => "FY$year", 'start_date' => "$year-01-01", 'end_date' => "$year-12-31"]);
        $org = DB::table('organizations')->insertGetId(['name' => 'Acme Ltd', 'email' => 'ap@acme.example']);
        $this->customer = DB::table('customers')->insertGetId(['organization_id' => $org, 'ar_account_id' => $this->acct['1100'], 'currency_code' => 'USD']);
        $sup = DB::table('organizations')->insertGetId(['name' => 'Parts Co']);
        DB::table('suppliers')->insert(['organization_id' => $sup, 'ap_account_id' => $this->acct['2000'], 'currency_code' => 'USD']);
        $this->product = DB::table('products')->insertGetId(['sku' => 'WID', 'name' => 'Widget', 'unit_price' => '12.5', 'tax_code' => 'STD',
            'revenue_account_id' => $this->acct['4000'], 'track_inventory' => true, 'inventory_account_id' => $this->acct['1200'],
            'cogs_account_id' => $this->acct['5000']]);
        $this->warehouse = DB::table('warehouses')->insertGetId(['code' => 'MAIN', 'name' => 'Main']);
    }

    private function login(?int $userId = null): void
    {
        $this->session = Sessions::create(Users::get($userId ?? $this->adminId));
    }

    private function cookies(): static
    {
        return $this->session === null ? $this : $this->withUnencryptedCookies([Sessions::COOKIE => $this->session]);
    }

    private function page(string $url, int $status = 200): string
    {
        $r = $this->cookies()->get($url);
        $this->assertSame($status, $r->getStatusCode(), "GET $url");

        return $r->getContent();
    }

    private function submit(string $url, array $data = []): TestResponse
    {
        return $this->cookies()->post($url, $data + ['_token' => hash_hmac('sha256', 'csrf', (string) $this->session)]);
    }

    private function between(string $html, string $from, string $to): string
    {
        return explode($to, explode($from, $html, 2)[1], 2)[0];
    }

    private function invoice(string $number = 'INV-1', string $qty = '2', string $price = '10', ?string $date = null, ?string $due = null): object
    {
        $r = $this->submit('/sales-invoices/new', [
            'customer_id' => $this->customer, 'invoice_number' => $number, 'invoice_date' => $date ?? Dates::today(),
            'due_date' => $due ?? '', 'currency_code' => 'USD', 'line_product_id' => [(string) $this->product],
            'line_description' => ['Widgets'], 'line_quantity' => [$qty], 'line_price' => [$price], 'line_account' => [''],
            'line_tax_code' => [''], 'line_tax_rate' => ['0'],
        ]);
        $this->assertSame(302, $r->getStatusCode(), $r->getContent());

        return DB::table('sales_invoices')->where('invoice_number', $number)->first();
    }

    private function invoiceRow(int $id): ?object
    {
        return DB::table('sales_invoices')->where('id', $id)->first();
    }

    // G: general

    public function test_login_is_the_only_page_without_a_session(): void // G1, G2
    {
        $this->get('/sales-invoices')->assertRedirect('/login?next=%2Fsales-invoices');
        $this->post('/login', ['email' => 'admin@example.com', 'password' => 'wrong'])->assertSee('Invalid email or password');
        $r = $this->post('/login', ['email' => ' ADMIN@example.com ', 'password' => 'admin-pass', 'next' => '/products']);
        $r->assertRedirect('/products');
        $this->session = $r->getCookie(Sessions::COOKIE, decrypt: false)->getValue();
        $this->assertStringContainsString('Ada Admin', $this->page('/'));
        $this->submit('/logout')->assertRedirect('/login');
        $this->cookies()->get('/')->assertRedirect();
    }

    public function test_every_navigation_link_works(): void // G3
    {
        $this->login();
        preg_match_all('/<a href="(\/[^"]*)"/', $this->between($this->page('/'), '<nav class="sidebar"', '</nav>'), $m);
        $links = array_unique($m[1]);
        $this->assertGreaterThan(25, count($links));
        foreach ($links as $url) {
            $this->page($url);
        }
    }

    public function test_admin_actions_are_hidden_from_users(): void // G4
    {
        $this->login($this->clerkId);
        $this->assertStringNotContainsString('href="/users"', $this->page('/'));
        $this->page('/users', 403);
        $this->assertStringContainsString('Only administrators can change these', $this->page('/settings'));
        $inv = $this->invoice();
        $this->submit("/sales-invoices/{$inv->id}/post");
        $this->assertStringNotContainsString('>Unpost<', $this->page("/sales-invoices/{$inv->id}"));
    }

    public function test_refusals_show_the_server_message(): void // G5
    {
        $this->login();
        $this->submit('/organizations/new', ['name' => ''])->assertSee('name is required');
        $this->submit('/organizations/new', ['name' => 'X', 'country_code' => 'ZZ'])->assertSee('unknown country_code');
        $inv = $this->invoice(price: '0');
        $this->submit("/sales-invoices/{$inv->id}/post")->assertSee('total must be greater than zero');
    }

    public function test_forms_need_the_session_token(): void
    {
        $this->login();
        $this->cookies()->post('/organizations/new', ['name' => 'Forged'])->assertStatus(419);
        $this->assertFalse(DB::table('organizations')->where('name', 'Forged')->exists());
    }

    public function test_deletes_ask_first(): void // G6
    {
        $this->login();
        $inv = $this->invoice();
        $this->assertStringContainsString('cannot be undone', $this->page("/sales-invoices/{$inv->id}/delete"));
        $this->assertNotNull($this->invoiceRow($inv->id));
        $this->submit("/sales-invoices/{$inv->id}/delete");
        $this->assertNull($this->invoiceRow($inv->id));
    }

    public function test_amounts_are_exact(): void // G7
    {
        $this->login();
        $inv = $this->invoice(qty: '1.00005', price: '10.0001');
        $detail = $this->page("/sales-invoices/{$inv->id}");
        $this->assertStringContainsString('10.0011', $detail); // round(1.0001 × 10.0001, 4)
        $this->assertStringContainsString('USD', $detail);
    }

    public function test_unknown_address_is_not_found(): void // G8
    {
        $this->login();
        $this->assertStringContainsString('Not found', $this->page('/no-such-page', 404));
        $this->page('/sales-invoices/999999', 404);
    }

    // H: home

    public function test_home_shows_outstanding_and_overdue(): void // H1–H5
    {
        $this->login();
        $inv = $this->invoice(date: Dates::addDays(Dates::today(), -40), due: Dates::addDays(Dates::today(), -5));
        $this->submit("/sales-invoices/{$inv->id}/post");
        $home = $this->page('/');
        $this->assertStringContainsString('USD 20.00', $home);
        $this->assertStringContainsString("/sales-invoices/{$inv->id}", $home);
        foreach (['sales-invoices', 'customer-payments', 'purchase-bills', 'supplier-payments', 'sales-orders', 'purchase-orders'] as $c) {
            $this->assertStringContainsString("/$c/new", $home);
        }
    }

    // M: master data

    public function test_master_data_forms(): void // M1–M6
    {
        $this->login();
        $this->submit('/organizations/new', ['name' => 'Globex', 'country_code' => 'US'])->assertRedirect('/organizations');
        $org = DB::table('organizations')->where('name', 'Globex')->value('id');
        $this->submit('/customers/new', ['organization_id' => $org, 'customer_number' => 'C-9', 'credit_limit' => '500']);
        $c = DB::table('customers')->where('organization_id', $org)->first();
        // The organization is read-only on edit, and deactivation is through the form.
        $this->submit("/customers/{$c->id}", ['organization_id' => 1, 'customer_number' => 'C-9']);
        $c = DB::table('customers')->where('id', $c->id)->first();
        $this->assertSame([$org, false, null], [$c->organization_id, $c->is_active, $c->credit_limit]);
        $this->assertStringContainsString('Inactive', $this->page('/customers'));
        $parents = $this->between($this->page("/accounts/{$this->acct['1000']}"), 'name="parent_id"', '</select>');
        $this->assertStringNotContainsString("value=\"{$this->acct['1000']}\"", $parents); // M4
        $this->page('/tax-codes/STD');
        $this->page('/payment-terms/NET30');
        $this->page("/warehouses/{$this->warehouse}");
    }

    public function test_users_and_settings(): void // M7, M8
    {
        $this->login();
        $this->submit('/users/new', ['email' => 'new@example.com', 'full_name' => 'New', 'password' => 'short'])->assertSee('at least 8 characters');
        $this->submit("/users/{$this->adminId}", ['email' => 'admin@example.com', 'full_name' => 'Ada'])->assertSee('cannot deactivate yourself');
        $this->submit("/users/{$this->clerkId}/password", ['password' => 'another-pass']);
        $this->assertNotNull(Users::authenticate('clerk@example.com', 'another-pass'));
        $this->assertStringContainsString('FX gain/loss', $this->page('/settings'));
    }

    // D, P: documents and payments

    public function test_invoice_lifecycle(): void // D1–D7
    {
        $this->login();
        $this->assertStringContainsString('id="client-data"', $this->page('/sales-invoices/new')); // prefill data for app.js
        $inv = $this->invoice();
        $this->assertStringContainsString('INV-1', $this->page('/sales-invoices'));
        $detail = $this->page("/sales-invoices/{$inv->id}");
        foreach (['Post', 'Edit', 'Delete', '/pdf', 'Email'] as $text) {
            $this->assertStringContainsString($text, $detail);
        }
        $this->submit("/sales-invoices/{$inv->id}/post");
        $inv = $this->invoiceRow($inv->id);
        $this->assertSame('posted', $inv->status);
        $detail = $this->page("/sales-invoices/{$inv->id}");
        $this->assertStringContainsString("/journal-entries/{$inv->journal_entry_id}", $detail);
        $this->assertStringContainsString('Unpost', $detail);
        $this->submit("/sales-invoices/{$inv->id}/email", ['to' => ''])->assertSee('not configured'); // D7: 501 when disabled
        $this->assertSame('application/pdf', $this->cookies()->get("/api/sales-invoices/{$inv->id}/pdf")->headers->get('Content-Type'));
        $this->submit("/sales-invoices/{$inv->id}/unpost");
        $this->assertSame('draft', $this->invoiceRow($inv->id)->status);
    }

    public function test_payment_and_apply(): void // P1–P4
    {
        $this->login();
        $inv = $this->invoice();
        $this->submit("/sales-invoices/{$inv->id}/post");
        $r = $this->submit('/customer-payments/new', ['customer_id' => $this->customer, 'payment_date' => Dates::today(),
            'currency_code' => 'USD', 'amount' => '15', 'method' => 'transfer', 'deposit_account_id' => $this->acct['1000']]);
        $pay = DB::table('customer_payments')->value('id');
        $r->assertRedirect("/customer-payments/$pay");
        $this->submit("/customer-payments/$pay/post");
        $this->submit("/customer-payments/$pay/apply");
        $detail = $this->page("/customer-payments/$pay");
        $this->assertStringContainsString('INV-1', $detail);
        $this->assertStringContainsString('15.00', $detail);
        $this->assertStringContainsString('Transfer', $this->page('/customer-payments'));
    }

    // O: orders

    public function test_order_fulfilment(): void // O1–O7
    {
        $this->login();
        $r = $this->submit('/sales-orders/new', ['customer_id' => $this->customer, 'order_number' => 'SO-1', 'order_date' => Dates::today(),
            'currency_code' => 'USD', 'line_product_id' => [(string) $this->product], 'line_description' => ['Widgets'],
            'line_quantity' => ['5'], 'line_price' => ['12.5'], 'line_account' => [''], 'line_tax_code' => [''], 'line_tax_rate' => ['0']]);
        $order = DB::table('sales_orders')->where('order_number', 'SO-1')->value('id');
        $r->assertRedirect("/sales-orders/$order");
        $this->submit("/sales-orders/$order/confirm");
        $line = DB::table('sales_order_lines')->where('order_id', $order)->value('id');
        $this->assertStringContainsString("name=\"qty_$line\"", $this->page("/sales-orders/$order/invoice"));
        $r = $this->submit("/sales-orders/$order/invoice", ['number' => 'INV-SO-1', 'date' => Dates::today(), "qty_$line" => '2']);
        $inv = DB::table('sales_invoices')->where('invoice_number', 'INV-SO-1')->value('id');
        $r->assertRedirect("/sales-invoices/$inv");
        $this->assertStringContainsString('cannot be edited', $this->page("/sales-invoices/$inv"));
        $this->submit("/sales-orders/$order/ship", ['warehouse_id' => $this->warehouse, 'movement_date' => Dates::today(), "qty_$line" => '5'])
            ->assertSee('Stock movement');
        $detail = $this->page("/sales-orders/$order");
        $this->assertStringContainsString('Partial', $detail);
        $this->assertStringNotContainsString('Cancel order', $detail); // O4: fulfilled orders cannot be cancelled
    }

    // S: inventory

    public function test_stock_movements(): void // S1–S3
    {
        $this->login();
        $r = $this->submit('/stock-movements/new', ['product_id' => $this->product, 'warehouse_id' => $this->warehouse,
            'movement_type' => 'issue', 'movement_date' => Dates::today(), 'quantity' => '3', 'unit_cost' => '2']);
        $sm = DB::table('stock_movements')->first();
        $this->assertSame('-3.0000', $sm->quantity); // S2: signed by the type
        $r->assertRedirect("/stock-movements/{$sm->id}");
        $this->submit("/stock-movements/{$sm->id}/post");
        $this->assertNotNull(DB::table('stock_movements')->where('id', $sm->id)->value('journal_entry_id'));
        $this->assertStringContainsString('Unpost', $this->page("/stock-movements/{$sm->id}"));
        $this->assertStringContainsString('WID', $this->page('/inventory-valuation'));
    }

    // R: reports

    public function test_reports(): void // R1–R8
    {
        $this->login();
        $inv = $this->invoice();
        $this->submit("/sales-invoices/{$inv->id}/post");
        $inv = $this->invoiceRow($inv->id);
        $this->assertStringContainsString('Net income', $this->page('/reports/profit-and-loss'));
        $this->assertStringContainsString('Current earnings', $this->page('/reports/balance-sheet?as_of='.Dates::today()));
        $this->assertStringContainsString('Opening cash', $this->page('/reports/cash-flow'));
        $this->assertStringContainsString("/accounts/{$this->acct['1100']}/ledger", $this->page('/reports/trial-balance'));
        $this->assertStringContainsString('20.00', $this->page("/accounts/{$this->acct['1100']}/ledger"));
        $this->assertStringContainsString('Accounts Receivable', $this->page("/journal-entries/{$inv->journal_entry_id}"));
        $this->assertStringContainsString('Acme Ltd', $this->page('/reports/ar-aging'));
        $this->page('/reports/ap-aging');
        $this->assertStringContainsString('must be a YYYY-MM-DD', $this->page('/reports/profit-and-loss?from=yesterday'));
    }

    // A: accounting

    public function test_periods_and_year_end(): void // A1, A2
    {
        $this->login();
        $this->submit('/fiscal-years/new', ['name' => 'FY1990', 'start_date' => '1990-01-01', 'end_date' => '1990-12-31'])->assertRedirect('/periods');
        $y = DB::table('fiscal_years')->where('name', 'FY1990')->value('id');
        $this->submit('/accounting-periods/new', ['fiscal_year_id' => $y, 'name' => '1990-01', 'start_date' => '1990-01-01', 'end_date' => '1990-01-31']);
        $this->assertStringContainsString('value="1990-02"', $this->page('/accounting-periods/new')); // the month after the latest period
        $this->assertStringContainsString('Retained Earnings', $this->page("/fiscal-years/$y/close"));
        $this->submit("/fiscal-years/$y/close", ['retained_earnings_account_id' => $this->acct['3000']]);
        $this->assertSame('closed', DB::table('fiscal_years')->where('id', $y)->value('status'));
        $this->login($this->clerkId);
        $this->page("/fiscal-years/$y/close", 403);
    }

    public function test_exchange_rates(): void // A3
    {
        $this->login();
        $this->submit('/exchange-rates/new', ['currency_code' => 'EUR', 'rate_date' => '2026-01-02', 'rate' => '1.125']);
        $this->assertStringContainsString('1.125', $this->page('/exchange-rates'));
        $this->submit('/exchange-rates/EUR/2026-01-02', ['rate' => '1.2']);
        $this->assertStringContainsString('1.2', $this->page('/exchange-rates'));
        $this->submit('/exchange-rates/EUR/2026-01-02/delete');
        $this->assertStringNotContainsString('EUR', explode('<table', $this->page('/exchange-rates'))[1] ?? '');
    }

    public function test_bank_reconciliation(): void // A4, A5
    {
        $this->login();
        $accounts = $this->between($this->page('/bank-statements/new'), 'name="account_id"', '</select>');
        $this->assertStringContainsString('1000 Cash', $accounts);
        $this->assertStringNotContainsString('1100', $accounts); // cash accounts only
        $pay = DB::table('customer_payments')->insertGetId(['customer_id' => $this->customer, 'payment_date' => Dates::today(),
            'currency_code' => 'USD', 'amount' => '40', 'deposit_account_id' => $this->acct['1000']]);
        Posting::postPayment(Kinds::payment('customer-payments'), $pay);
        $this->submit('/bank-statements/new', ['account_id' => $this->acct['1000'], 'statement_date' => Dates::today(),
            'opening_balance' => '0', 'closing_balance' => '40', 'reference' => 'S1']);
        $st = DB::table('bank_statements')->value('id');
        $this->submit("/bank-statements/$st/import", ['csv' => "date,description,amount\nnot-a-date,x,1\n"])->assertSee('not a YYYY-MM-DD date');
        $this->submit("/bank-statements/$st/import", ['csv' => "date,description,amount\n".Dates::today().",Deposit,40\n"]);
        $this->assertStringContainsString('Match', $this->page("/bank-statements/$st"));
        $this->submit("/bank-statements/$st/auto-match");
        $this->submit("/bank-statements/$st/reconcile");
        $this->assertSame('reconciled', DB::table('bank_statements')->where('id', $st)->value('status'));
        $this->assertStringContainsString('Reopen', $this->page("/bank-statements/$st"));
    }
}
