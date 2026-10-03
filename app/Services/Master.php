<?php

namespace App\Services;

use App\Errors\ApiError;
use App\Http\Body;
use App\Support\Dec;
use Illuminate\Support\Facades\DB;

/**
 * Master data (spec/api.md §5.2–5.6, §5.8; domain §3).
 *
 * Updates are full replacements: whatever the body omits becomes null or
 * false. Uniqueness and foreign keys are enforced by the schema, and their
 * violations surface as 409 and 422 (App\Errors\DatabaseErrors).
 */
final class Master
{
    public const ACCOUNT_TYPES = ['asset', 'liability', 'equity', 'revenue', 'expense'];

    public const ACTIVITIES = ['operating', 'investing', 'financing'];

    /** A row by key, or 404. */
    public static function find(string $table, string $key, int|string $value, string $what): object
    {
        return DB::table($table)->where($key, $value)->first() ?? throw new ApiError(404, "$what not found");
    }

    // ------------------------------------------------------------------
    // Organizations
    // ------------------------------------------------------------------

    public static function organizationJson(object $o): array
    {
        return [
            'id' => $o->id, 'name' => $o->name, 'legal_name' => $o->legal_name, 'tax_id' => $o->tax_id,
            'country_code' => $o->country_code, 'default_currency' => $o->default_currency,
            'email' => $o->email, 'is_self' => $o->is_self,
        ];
    }

    private static function organizationFields(Body $b): array
    {
        return [
            'name' => $b->requiredStr('name'),
            'legal_name' => $b->text('legal_name'),
            'tax_id' => $b->text('tax_id'),
            'country_code' => $b->text('country_code'),
            'default_currency' => $b->text('default_currency'),
            'email' => $b->text('email'),
            'is_self' => $b->bool('is_self'),
        ];
    }

    private static function checkSelf(array $fields, ?int $id = null): void
    {
        if ($fields['is_self'] && DB::table('organizations')->where('is_self', true)
            ->when($id, fn ($q) => $q->where('id', '<>', $id))->exists()) {
            throw new ApiError(409, 'another organization is already marked as our own');
        }
    }

    public static function organizations(): array
    {
        return DB::table('organizations')->orderBy('name')->orderBy('id')->get()
            ->map(self::organizationJson(...))->all();
    }

    public static function organization(int $id): object
    {
        return self::find('organizations', 'id', $id, 'organization');
    }

    public static function createOrganization(Body $b): int
    {
        $fields = self::organizationFields($b);
        self::checkSelf($fields);

        return DB::table('organizations')->insertGetId($fields);
    }

    public static function updateOrganization(int $id, Body $b): void
    {
        $fields = self::organizationFields($b);
        self::organization($id);
        self::checkSelf($fields, $id);
        DB::table('organizations')->where('id', $id)->update($fields);
    }

    // ------------------------------------------------------------------
    // Customers and suppliers
    // ------------------------------------------------------------------

    public static function customerJson(object $c): array
    {
        return [
            'id' => $c->id, 'organization_id' => $c->organization_id, 'customer_number' => $c->customer_number,
            'ar_account_id' => $c->ar_account_id, 'payment_terms_code' => $c->payment_terms_code,
            'currency_code' => $c->currency_code, 'tax_code' => $c->tax_code,
            'credit_limit' => Dec::fmt4($c->credit_limit), 'is_active' => $c->is_active,
        ];
    }

    public static function supplierJson(object $s): array
    {
        return [
            'id' => $s->id, 'organization_id' => $s->organization_id, 'supplier_number' => $s->supplier_number,
            'ap_account_id' => $s->ap_account_id, 'payment_terms_code' => $s->payment_terms_code,
            'currency_code' => $s->currency_code, 'tax_code' => $s->tax_code, 'is_active' => $s->is_active,
        ];
    }

    private static function partyFields(Body $b, string $number, string $control): array
    {
        return [
            'organization_id' => $b->requiredId('organization_id'),
            $number => $b->text($number),
            $control => $b->int($control),
            'payment_terms_code' => $b->text('payment_terms_code'),
            'currency_code' => $b->text('currency_code'),
            'tax_code' => $b->text('tax_code'),
        ];
    }

    private static function customerFields(Body $b): array
    {
        $fields = self::partyFields($b, 'customer_number', 'ar_account_id');
        $limit = $b->decimal('credit_limit');
        if ($limit !== null && $limit->isNegative()) {
            throw new ApiError(422, 'credit_limit must not be negative');
        }
        $fields['credit_limit'] = $limit?->toString();

        return $fields;
    }

    public static function customers(): array
    {
        return DB::table('customers')->orderBy('id')->get()->map(self::customerJson(...))->all();
    }

    public static function customer(int $id): object
    {
        return self::find('customers', 'id', $id, 'customer');
    }

    public static function createCustomer(Body $b): int
    {
        return DB::table('customers')->insertGetId(self::customerFields($b));
    }

    public static function updateCustomer(int $id, Body $b): void
    {
        $b->requiredId('organization_id');
        self::customer($id);
        $fields = self::customerFields($b) + ['is_active' => $b->bool('is_active')];
        DB::table('customers')->where('id', $id)->update($fields);
    }

    public static function suppliers(): array
    {
        return DB::table('suppliers')->orderBy('id')->get()->map(self::supplierJson(...))->all();
    }

    public static function supplier(int $id): object
    {
        return self::find('suppliers', 'id', $id, 'supplier');
    }

    public static function createSupplier(Body $b): int
    {
        return DB::table('suppliers')->insertGetId(self::partyFields($b, 'supplier_number', 'ap_account_id'));
    }

    public static function updateSupplier(int $id, Body $b): void
    {
        $b->requiredId('organization_id');
        self::supplier($id);
        $fields = self::partyFields($b, 'supplier_number', 'ap_account_id') + ['is_active' => $b->bool('is_active')];
        DB::table('suppliers')->where('id', $id)->update($fields);
    }

    // ------------------------------------------------------------------
    // Products
    // ------------------------------------------------------------------

    public static function productJson(object $p): array
    {
        return [
            'id' => $p->id, 'sku' => $p->sku, 'name' => $p->name, 'description' => $p->description,
            'unit_price' => Dec::fmt4($p->unit_price), 'currency_code' => $p->currency_code,
            'revenue_account_id' => $p->revenue_account_id, 'tax_code' => $p->tax_code,
            'track_inventory' => $p->track_inventory, 'inventory_account_id' => $p->inventory_account_id,
            'cogs_account_id' => $p->cogs_account_id, 'is_active' => $p->is_active,
        ];
    }

    private static function productFields(Body $b): array
    {
        return [
            'sku' => $b->requiredStr('sku'),
            'name' => $b->requiredStr('name'),
            'description' => $b->text('description'),
            'unit_price' => $b->decimal('unit_price', Dec::MONEY, Dec::zero())->toString(),
            'currency_code' => $b->text('currency_code'),
            'revenue_account_id' => $b->int('revenue_account_id'),
            'tax_code' => $b->text('tax_code'),
            'track_inventory' => $b->bool('track_inventory'),
            'inventory_account_id' => $b->int('inventory_account_id'),
            'cogs_account_id' => $b->int('cogs_account_id'),
        ];
    }

    private static function productRequired(Body $b): void
    {
        $b->requiredStr('sku');
        $b->requiredStr('name');
    }

    public static function products(): array
    {
        return DB::table('products')->orderBy('sku')->orderBy('id')->get()->map(self::productJson(...))->all();
    }

    public static function product(int $id): object
    {
        return self::find('products', 'id', $id, 'product');
    }

    public static function createProduct(Body $b): int
    {
        self::productRequired($b);

        return DB::table('products')->insertGetId(self::productFields($b));
    }

    public static function updateProduct(int $id, Body $b): void
    {
        self::productRequired($b);
        self::product($id);
        DB::table('products')->where('id', $id)->update(self::productFields($b) + ['is_active' => $b->bool('is_active')]);
    }

    // ------------------------------------------------------------------
    // Chart of accounts
    // ------------------------------------------------------------------

    public static function accountJson(object $a): array
    {
        return [
            'id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'account_type' => $a->account_type,
            'parent_id' => $a->parent_id, 'currency_code' => $a->currency_code,
            'is_postable' => $a->is_postable, 'is_active' => $a->is_active, 'is_cash' => $a->is_cash,
            'cash_flow_activity' => $a->cash_flow_activity,
        ];
    }

    private static function accountRequired(Body $b): void
    {
        $b->requiredStr('code');
        $b->requiredStr('name');
        $b->requiredStr('account_type');
    }

    private static function accountFields(Body $b, ?int $id = null): array
    {
        $fields = [
            'code' => $b->requiredStr('code'),
            'name' => $b->requiredStr('name'),
            'account_type' => $b->requiredStr('account_type'),
            'parent_id' => $b->int('parent_id'),
            'currency_code' => $b->text('currency_code'),
            'is_postable' => $b->bool('is_postable'),
            'is_cash' => $b->bool('is_cash'),
            'cash_flow_activity' => $b->text('cash_flow_activity') ?? 'operating',
        ];
        if (! in_array($fields['account_type'], self::ACCOUNT_TYPES, true)) {
            throw new ApiError(422, 'account_type must be one of '.implode(', ', self::ACCOUNT_TYPES));
        }
        if (! in_array($fields['cash_flow_activity'], self::ACTIVITIES, true)) {
            throw new ApiError(422, 'cash_flow_activity must be one of '.implode(', ', self::ACTIVITIES));
        }
        if ($fields['is_cash'] && $fields['account_type'] !== 'asset') {
            throw new ApiError(422, 'only an asset account can be a cash account');
        }
        $parent = $fields['parent_id'];
        if ($parent !== null) {
            if ($id !== null && $parent === $id) {
                throw new ApiError(422, 'an account cannot be its own parent');
            }
            if (! DB::table('accounts')->where('id', $parent)->exists()) {
                throw new ApiError(422, 'unknown parent_id');
            }
        }

        return $fields;
    }

    public static function accounts(): array
    {
        return DB::table('accounts')->orderBy('code')->orderBy('id')->get()->map(self::accountJson(...))->all();
    }

    public static function account(int $id): object
    {
        return self::find('accounts', 'id', $id, 'account');
    }

    public static function createAccount(Body $b): int
    {
        self::accountRequired($b);

        return DB::table('accounts')->insertGetId(self::accountFields($b));
    }

    public static function updateAccount(int $id, Body $b): void
    {
        self::accountRequired($b);
        self::account($id);
        DB::table('accounts')->where('id', $id)->update(self::accountFields($b, $id) + ['is_active' => $b->bool('is_active')]);
    }

    /** Whether the account exists and can carry journal lines. */
    public static function isPostable(?int $id, array $where = []): bool
    {
        return $id !== null && DB::table('accounts')
            ->where(['id' => $id, 'is_postable' => true, 'is_active' => true] + $where)->exists();
    }

    // ------------------------------------------------------------------
    // Tax codes, payment terms, warehouses
    // ------------------------------------------------------------------

    public static function taxCodeJson(object $t): array
    {
        return [
            'code' => $t->code, 'name' => $t->name, 'rate' => Dec::fmt4($t->rate),
            'tax_account_id' => $t->tax_account_id, 'is_active' => $t->is_active,
        ];
    }

    private static function taxCodeFields(Body $b): array
    {
        $rate = $b->decimal('rate', Dec::RATE, Dec::zero());
        if ($rate->isNegative()) {
            throw new ApiError(422, 'rate must not be negative');
        }

        return ['name' => $b->requiredStr('name'), 'rate' => $rate->toString(), 'tax_account_id' => $b->int('tax_account_id')];
    }

    public static function taxCodes(): array
    {
        return DB::table('tax_codes')->orderBy('code')->get()->map(self::taxCodeJson(...))->all();
    }

    public static function taxCode(string $code): object
    {
        return self::find('tax_codes', 'code', $code, 'tax code');
    }

    public static function createTaxCode(Body $b): string
    {
        $code = $b->requiredStr('code');
        $b->requiredStr('name');
        $fields = self::taxCodeFields($b);
        if (DB::table('tax_codes')->where('code', $code)->exists()) {
            throw new ApiError(409, 'tax code already exists');
        }
        DB::table('tax_codes')->insert(['code' => $code] + $fields);

        return $code;
    }

    public static function updateTaxCode(string $code, Body $b): void
    {
        $b->requiredStr('name');
        self::taxCode($code);
        DB::table('tax_codes')->where('code', $code)->update(self::taxCodeFields($b) + ['is_active' => $b->bool('is_active')]);
    }

    public static function paymentTermJson(object $p): array
    {
        return ['code' => $p->code, 'name' => $p->name, 'due_days' => $p->due_days];
    }

    private static function dueDays(Body $b): int
    {
        $days = $b->int('due_days') ?? 0;
        if ($days < 0) {
            throw new ApiError(422, 'due_days must not be negative');
        }

        return $days;
    }

    public static function paymentTerms(): array
    {
        return DB::table('payment_terms')->orderBy('due_days')->orderBy('code')->get()
            ->map(self::paymentTermJson(...))->all();
    }

    public static function paymentTerm(string $code): object
    {
        return self::find('payment_terms', 'code', $code, 'payment term');
    }

    public static function createPaymentTerm(Body $b): string
    {
        $code = $b->requiredStr('code');
        $name = $b->requiredStr('name');
        $days = self::dueDays($b);
        if (DB::table('payment_terms')->where('code', $code)->exists()) {
            throw new ApiError(409, 'payment term already exists');
        }
        DB::table('payment_terms')->insert(['code' => $code, 'name' => $name, 'due_days' => $days]);

        return $code;
    }

    public static function updatePaymentTerm(string $code, Body $b): void
    {
        $name = $b->requiredStr('name');
        self::paymentTerm($code);
        DB::table('payment_terms')->where('code', $code)->update(['name' => $name, 'due_days' => self::dueDays($b)]);
    }

    public static function warehouseJson(object $w): array
    {
        return ['id' => $w->id, 'code' => $w->code, 'name' => $w->name, 'address_id' => $w->address_id, 'is_active' => $w->is_active];
    }

    private static function warehouseFields(Body $b): array
    {
        return ['code' => $b->requiredStr('code'), 'name' => $b->requiredStr('name'), 'address_id' => $b->int('address_id')];
    }

    public static function warehouses(): array
    {
        return DB::table('warehouses')->orderBy('code')->orderBy('id')->get()->map(self::warehouseJson(...))->all();
    }

    public static function warehouse(int $id): object
    {
        return self::find('warehouses', 'id', $id, 'warehouse');
    }

    public static function createWarehouse(Body $b): int
    {
        return DB::table('warehouses')->insertGetId(self::warehouseFields($b));
    }

    public static function updateWarehouse(int $id, Body $b): void
    {
        $fields = self::warehouseFields($b);
        self::warehouse($id);
        DB::table('warehouses')->where('id', $id)->update($fields + ['is_active' => $b->bool('is_active')]);
    }

    // ------------------------------------------------------------------
    // Ledger settings and exchange rates
    // ------------------------------------------------------------------

    public static function settings(): object
    {
        return DB::table('gl_settings')->where('id', 1)->first();
    }

    public static function settingsJson(object $s): array
    {
        return ['base_currency' => $s->base_currency, 'fx_gain_loss_account_id' => $s->fx_gain_loss_account_id];
    }

    public static function baseCurrency(): string
    {
        return self::settings()->base_currency;
    }

    private static function currency(string $code, string $name = 'currency_code'): string
    {
        $code = strtoupper(trim($code));
        if (! preg_match('/^[A-Z]{3}$/', $code) || ! DB::table('currencies')->where('code', $code)->exists()) {
            throw new ApiError(422, "unknown $name");
        }

        return $code;
    }

    public static function updateSettings(Body $b): void
    {
        $base = self::currency($b->requiredStr('base_currency'), 'base_currency');
        $fx = $b->int('fx_gain_loss_account_id');
        if ($fx !== null && ! self::isPostable($fx)) {
            throw new ApiError(422, 'fx_gain_loss_account_id must be a postable, active account');
        }
        $current = DB::table('gl_settings')->where('id', 1)->lockForUpdate()->first();
        if ($base !== $current->base_currency && DB::table('journal_entries')->exists()) {
            throw new ApiError(422, 'the base currency cannot change once journal entries exist');
        }
        DB::table('gl_settings')->where('id', 1)->update(['base_currency' => $base, 'fx_gain_loss_account_id' => $fx]);
    }

    public static function exchangeRateJson(object $r): array
    {
        return ['currency_code' => $r->currency_code, 'rate_date' => $r->rate_date, 'rate' => Dec::fmtRate($r->rate)];
    }

    public static function exchangeRates(): array
    {
        return DB::table('exchange_rates')->orderBy('currency_code')->orderByDesc('rate_date')->get()
            ->map(self::exchangeRateJson(...))->all();
    }

    private static function rate(Body $b): string
    {
        $rate = $b->decimal('rate', Dec::FX) ?? throw new ApiError(400, 'rate is required');
        if (! $rate->isPositive()) {
            throw new ApiError(422, 'rate must be greater than 0');
        }

        return $rate->toString();
    }

    public static function createExchangeRate(Body $b): array
    {
        $currency = $b->requiredStr('currency_code');
        $date = $b->requiredStr('rate_date');
        $b->requiredStr('rate');
        $currency = self::currency($currency);
        $date = \App\Support\Dates::parse($date, 'rate_date');
        $rate = self::rate($b);
        if (DB::table('exchange_rates')->where(['currency_code' => $currency, 'rate_date' => $date])->exists()) {
            throw new ApiError(409, 'a rate for that currency and date already exists');
        }
        DB::table('exchange_rates')->insert(['currency_code' => $currency, 'rate_date' => $date, 'rate' => $rate]);

        return ['currency_code' => $currency, 'rate_date' => $date];
    }

    private static function rateKey(string $currency, string $date): array
    {
        $key = [
            'currency_code' => strtoupper(trim($currency)),
            'rate_date' => \App\Support\Dates::parse($date, 'rate_date', 400),
        ];
        if (! DB::table('exchange_rates')->where($key)->exists()) {
            throw new ApiError(404, 'exchange rate not found');
        }

        return $key;
    }

    public static function updateExchangeRate(string $currency, string $date, Body $b): void
    {
        $key = self::rateKey($currency, $date);
        DB::table('exchange_rates')->where($key)->update(['rate' => self::rate($b)]);
    }

    public static function deleteExchangeRate(string $currency, string $date): void
    {
        DB::table('exchange_rates')->where(self::rateKey($currency, $date))->delete();
    }
}
