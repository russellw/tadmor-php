<?php

namespace App\Http\Controllers\Ui;

use App\Http\Body;
use App\Services\Master;
use App\Services\Users;
use App\Ui\Choices as Ch;
use App\Ui\Column;
use App\Ui\Field;
use App\Ui\Form;
use App\Ui\Ui;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Master data screens (domain §13.3, M1–M8). Each collection has a list, a
 * new form, and an edit form; the route's `resource` default picks it.
 */
class MasterController
{
    private static function active(): Field
    {
        return new Field('is_active', 'Active', 'bool');
    }

    /** The fields, plus Active when editing (M6: deactivated through the form). */
    private static function withActive(array $fields, ?array $record): array
    {
        return $record !== null ? [...$fields, self::active()] : $fields;
    }

    private static function partyRows(array $rows): array
    {
        $names = DB::table('organizations')->pluck('name', 'id');

        return array_map(fn ($r) => $r + ['organization_name' => $names[$r['organization_id']] ?? null], $rows);
    }

    private static function partyFields(string $number, string $control, string $controlLabel): \Closure
    {
        return function (?array $record) use ($number, $control, $controlLabel) {
            $fs = [
                new Field('organization_id', 'Organization', 'ref', Ch::organizations(), required: true, readonlyOnEdit: true),
                new Field($number, 'Number'),
                new Field($control, $controlLabel, 'ref', Ch::postableAccounts(), help: 'The control account its documents post to; posting needs it.'),
                new Field('payment_terms_code', 'Payment terms', 'select', Ch::paymentTerms()),
                new Field('currency_code', 'Currency', 'select', Ch::currencies()),
                new Field('tax_code', 'Default tax code', 'select', Ch::taxCodes()),
            ];
            if ($number === 'customer_number') {
                $fs[] = new Field('credit_limit', 'Credit limit', 'decimal');
            }

            return self::withActive($fs, $record);
        };
    }

    /** resource => its title, columns, fields, and service calls. */
    private static function resources(): array
    {
        $status = new Column('Status', 'is_active', kind: 'active');

        return [
            'organizations' => [
                'title' => 'Organizations', 'singular' => 'organization', 'rows' => Master::organizations(...),
                'columns' => [new Column('Name', 'name'), new Column('Legal name', 'legal_name'), new Column('Tax ID', 'tax_id'),
                    new Column('Country', 'country_code'), new Column('Currency', 'default_currency'),
                    new Column('Own company', 'is_self', kind: 'bool')],
                'fields' => fn ($r) => [
                    new Field('name', 'Name', required: true),
                    new Field('legal_name', 'Legal name'),
                    new Field('tax_id', 'Tax ID'),
                    new Field('country_code', 'Country', 'select', Ch::countries()),
                    new Field('default_currency', 'Default currency', 'select', Ch::currencies()),
                    new Field('email', 'Email', 'email', help: 'Documents are emailed here unless another recipient is given.'),
                    new Field('is_self', 'This is our own company (shown as the issuer on printed documents)', 'bool'),
                ],
                'get' => fn ($id) => Master::organizationJson(Master::organization($id)),
                'create' => Master::createOrganization(...), 'update' => Master::updateOrganization(...),
            ],
            'customers' => [
                'title' => 'Customers', 'singular' => 'customer', 'rows' => fn () => self::partyRows(Master::customers()),
                'columns' => [new Column('Organization', 'organization_name'), new Column('Number', 'customer_number'),
                    new Column('Currency', 'currency_code'), new Column('Tax code', 'tax_code'),
                    new Column('Terms', 'payment_terms_code'), new Column('Credit limit', 'credit_limit', true, 'amount'), $status],
                'fields' => self::partyFields('customer_number', 'ar_account_id', 'A/R account'),
                'get' => fn ($id) => Master::customerJson(Master::customer($id)),
                'create' => Master::createCustomer(...), 'update' => Master::updateCustomer(...),
            ],
            'suppliers' => [
                'title' => 'Suppliers', 'singular' => 'supplier', 'rows' => fn () => self::partyRows(Master::suppliers()),
                'columns' => [new Column('Organization', 'organization_name'), new Column('Number', 'supplier_number'),
                    new Column('Currency', 'currency_code'), new Column('Tax code', 'tax_code'),
                    new Column('Terms', 'payment_terms_code'), $status],
                'fields' => self::partyFields('supplier_number', 'ap_account_id', 'A/P account'),
                'get' => fn ($id) => Master::supplierJson(Master::supplier($id)),
                'create' => Master::createSupplier(...), 'update' => Master::updateSupplier(...),
            ],
            'products' => [
                'title' => 'Products', 'singular' => 'product', 'rows' => Master::products(...),
                'columns' => [new Column('SKU', 'sku'), new Column('Name', 'name'), new Column('Unit price', 'unit_price', true, 'amount'),
                    new Column('Currency', 'currency_code'), new Column('Tax code', 'tax_code'),
                    new Column('Inventory', 'track_inventory', kind: 'bool'), $status],
                'fields' => fn ($r) => self::withActive([
                    new Field('sku', 'SKU', required: true),
                    new Field('name', 'Name', required: true),
                    new Field('description', 'Description', 'textarea'),
                    new Field('unit_price', 'Unit price', 'decimal'),
                    new Field('currency_code', 'Currency', 'select', Ch::currencies()),
                    new Field('tax_code', 'Tax code', 'select', Ch::taxCodes()),
                    new Field('revenue_account_id', 'Revenue account', 'ref', Ch::postableAccounts(),
                        help: 'Used by invoice and credit-note lines that name no account.'),
                    new Field('track_inventory', 'Track inventory', 'bool'),
                    new Field('inventory_account_id', 'Inventory account', 'ref', Ch::postableAccounts(),
                        help: 'Stock postings use it; bill lines that name no account are expensed to it.'),
                    new Field('cogs_account_id', 'COGS account', 'ref', Ch::postableAccounts()),
                ], $r),
                'get' => fn ($id) => Master::productJson(Master::product($id)),
                'create' => Master::createProduct(...), 'update' => Master::updateProduct(...),
            ],
            'accounts' => [
                'title' => 'Chart of accounts', 'singular' => 'account', 'rows' => Master::accounts(...),
                'columns' => [new Column('Code', 'code'), new Column('Name', 'name'), new Column('Type', 'account_type', kind: 'status'),
                    new Column('Currency', 'currency_code'), new Column('Postable', 'is_postable', kind: 'bool'),
                    new Column('Cash', 'is_cash', kind: 'bool'), $status],
                'fields' => function ($r) {
                    $own = $r['id'] ?? null;
                    $parents = fn () => array_values(array_filter((Ch::accounts())(), fn ($a) => $a[0] !== $own)); // never itself (M4)

                    return self::withActive([
                        new Field('code', 'Code', required: true),
                        new Field('name', 'Name', required: true),
                        new Field('account_type', 'Type', 'select', Ch::accountTypes(), required: true),
                        new Field('parent_id', 'Parent', 'ref', $parents),
                        new Field('currency_code', 'Currency', 'select', Ch::currencies()),
                        new Field('is_postable', 'Postable (unticked makes a summary account)', 'bool'),
                        new Field('is_cash', 'Cash account (assets only)', 'bool'),
                        new Field('cash_flow_activity', 'Cash-flow activity', 'select', Ch::activities()),
                    ], $r);
                },
                'get' => fn ($id) => Master::accountJson(Master::account($id)),
                'create' => Master::createAccount(...), 'update' => Master::updateAccount(...),
            ],
            'tax-codes' => [
                'title' => 'Tax codes', 'singular' => 'tax code', 'rows' => Master::taxCodes(...), 'byCode' => true,
                'columns' => [new Column('Code', 'code'), new Column('Name', 'name'), new Column('Rate %', 'rate', true, 'qty'), $status],
                'fields' => fn ($r) => self::withActive([
                    new Field('code', 'Code', required: true, readonlyOnEdit: true),
                    new Field('name', 'Name', required: true),
                    new Field('rate', 'Rate (percent)', 'decimal'),
                    new Field('tax_account_id', 'Tax account', 'ref', Ch::postableAccounts(),
                        help: 'Taxed lines post here; without one, taxed lines cannot post.'),
                ], $r),
                'get' => fn ($code) => Master::taxCodeJson(Master::taxCode($code)),
                'create' => Master::createTaxCode(...), 'update' => Master::updateTaxCode(...),
            ],
            'payment-terms' => [
                'title' => 'Payment terms', 'singular' => 'payment term', 'rows' => Master::paymentTerms(...), 'byCode' => true,
                'columns' => [new Column('Code', 'code'), new Column('Name', 'name'), new Column('Due days', 'due_days', true)],
                'fields' => fn ($r) => [
                    new Field('code', 'Code', required: true, readonlyOnEdit: true),
                    new Field('name', 'Name', required: true),
                    new Field('due_days', 'Due days', 'int'),
                ],
                'get' => fn ($code) => Master::paymentTermJson(Master::paymentTerm($code)),
                'create' => Master::createPaymentTerm(...), 'update' => Master::updatePaymentTerm(...),
            ],
            'warehouses' => [
                'title' => 'Warehouses', 'singular' => 'warehouse', 'rows' => Master::warehouses(...), 'keep' => ['address_id'],
                'columns' => [new Column('Code', 'code'), new Column('Name', 'name'), $status],
                'fields' => fn ($r) => self::withActive([new Field('code', 'Code', required: true), new Field('name', 'Name', required: true)], $r),
                'get' => fn ($id) => Master::warehouseJson(Master::warehouse($id)),
                'create' => Master::createWarehouse(...), 'update' => Master::updateWarehouse(...),
            ],
        ];
    }

    private static function resource(Request $request): array
    {
        $name = $request->route('resource');

        return self::resources()[$name] + ['name' => $name, 'byCode' => false, 'keep' => []];
    }

    private static function key(array $res, string $key): int|string
    {
        if ($res['byCode']) {
            return $key;
        }
        if (! ctype_digit($key) || strlen($key) > 9) {
            abort(404);
        }

        return (int) $key;
    }

    public function index(Request $request)
    {
        $res = self::resource($request);

        return Ui::listPage($res['title'], ($res['rows'])(), $res['columns'],
            fn ($r) => "/{$res['name']}/".rawurlencode((string) ($r['id'] ?? $r['code'])),
            ["/{$res['name']}/new", "New {$res['singular']}"]);
    }

    public function create(Request $request)
    {
        $res = self::resource($request);

        return Ui::crudForm($request, "New {$res['singular']}", ($res['fields'])(null), [], $res['create'],
            fn () => "/{$res['name']}", "/{$res['name']}");
    }

    public function edit(Request $request, string $key)
    {
        $res = self::resource($request);
        $key = self::key($res, $key);
        $record = ($res['get'])($key);
        // An update replaces the whole record, so fields the form does not show go back unchanged.
        $save = function (Body $b) use ($res, $key, $record) {
            $data = [];
            foreach ($res['keep'] as $k) {
                $data[$k] = $record[$k];
            }

            return ($res['update'])($key, $b->with($data));
        };

        return Ui::crudForm($request, "Edit {$res['singular']}", ($res['fields'])($record), $record, $save,
            fn () => "/{$res['name']}", "/{$res['name']}", editing: true);
    }

    // ------------------------------------------------------------------
    // M7 Users (administrators only)
    // ------------------------------------------------------------------

    public function users()
    {
        return Ui::listPage('Users', Users::all(), [
            new Column('Email', 'email'), new Column('Name', 'full_name'),
            new Column('Role', fn ($r) => $r['is_admin'] ? 'Administrator' : 'User'),
            new Column('Status', 'is_active', kind: 'active'),
        ], fn ($r) => "/users/{$r['id']}", ['/users/new', 'New user']);
    }

    public function newUser(Request $request)
    {
        return Ui::crudForm($request, 'New user', [
            new Field('email', 'Email', 'email', required: true), new Field('full_name', 'Name', required: true),
            new Field('password', 'Password', 'password', required: true, help: 'At least 8 characters.'),
            new Field('is_admin', 'Administrator', 'bool'),
        ], [], Users::create(...), fn () => '/users', '/users');
    }

    public function editUser(Request $request, string $id)
    {
        $id = self::key(['byCode' => false], $id);
        $record = Users::json(Users::get($id));

        return Ui::crudForm($request, 'Edit user', [
            new Field('email', 'Email', 'email', required: true), new Field('full_name', 'Name', required: true),
            self::active(), new Field('is_admin', 'Administrator', 'bool'),
        ], $record, fn (Body $b) => Users::update(Ui::user($request), $id, $b), fn () => '/users', '/users',
            extra: ['links' => [["/users/$id/password", "Reset this user's password"]]]);
    }

    public function userPassword(Request $request, string $id)
    {
        $id = self::key(['byCode' => false], $id);
        $record = Users::json(Users::get($id));

        return Ui::crudForm($request, "Reset password for {$record['email']}", [
            new Field('password', 'New password', 'password', required: true,
                help: 'At least 8 characters. Signs the user out everywhere.'),
        ], [], fn (Body $b) => Users::setPassword($id, $b), fn () => "/users/$id", "/users/$id");
    }

    // ------------------------------------------------------------------
    // M8 Settings
    // ------------------------------------------------------------------

    public function settings(Request $request)
    {
        $fields = [
            new Field('base_currency', 'Base currency', 'select', Ch::currencies(), required: true,
                help: 'Cannot change once any journal entry exists.'),
            new Field('fx_gain_loss_account_id', 'FX gain/loss account', 'ref', Ch::postableAccounts(),
                help: 'Realized exchange differences on settlement post here.'),
        ];
        $admin = Ui::user($request)->is_admin;
        $form = new Form($fields, Master::settingsJson(Master::settings()));
        $error = null;
        if ($request->isMethod('post') && $admin) {
            [, $error] = Ui::attempt(fn () => Master::updateSettings($form->body($request)));
            if ($error === null) {
                return redirect('/settings?saved=1');
            }
            $form->keep($request);
        }

        return view('form', ['title' => 'Settings', 'form' => $form->rows(), 'error' => $error, 'back' => '/',
            'readonly' => ! $admin, 'notice' => $request->query('saved') ? 'Settings saved.' : null]);
    }
}
