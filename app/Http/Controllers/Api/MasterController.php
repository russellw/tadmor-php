<?php

namespace App\Http\Controllers\Api;

use App\Http\Api;
use App\Http\Body;
use App\Services\Calendar;
use App\Services\Master;
use Illuminate\Http\Request;

/**
 * The list/get/create/update quartet of each master-data collection
 * (spec/api.md §5.2–5.7). The route's `resource` default picks the row.
 */
class MasterController
{
    /** resource => [list, get, to JSON, create, update, keyed by code rather than id] */
    private static function resources(): array
    {
        return [
            'organizations' => [Master::organizations(...), Master::organization(...), Master::organizationJson(...),
                Master::createOrganization(...), Master::updateOrganization(...), false],
            'customers' => [Master::customers(...), Master::customer(...), Master::customerJson(...),
                Master::createCustomer(...), Master::updateCustomer(...), false],
            'suppliers' => [Master::suppliers(...), Master::supplier(...), Master::supplierJson(...),
                Master::createSupplier(...), Master::updateSupplier(...), false],
            'products' => [Master::products(...), Master::product(...), Master::productJson(...),
                Master::createProduct(...), Master::updateProduct(...), false],
            'accounts' => [Master::accounts(...), Master::account(...), Master::accountJson(...),
                Master::createAccount(...), Master::updateAccount(...), false],
            'warehouses' => [Master::warehouses(...), Master::warehouse(...), Master::warehouseJson(...),
                Master::createWarehouse(...), Master::updateWarehouse(...), false],
            'tax-codes' => [Master::taxCodes(...), Master::taxCode(...), Master::taxCodeJson(...),
                Master::createTaxCode(...), Master::updateTaxCode(...), true],
            'payment-terms' => [Master::paymentTerms(...), Master::paymentTerm(...), Master::paymentTermJson(...),
                Master::createPaymentTerm(...), Master::updatePaymentTerm(...), true],
            'fiscal-years' => [Calendar::fiscalYears(...), Calendar::fiscalYear(...), Calendar::fiscalYearJson(...),
                Calendar::createFiscalYear(...), Calendar::updateFiscalYear(...), false],
            'accounting-periods' => [Calendar::periods(...), Calendar::period(...), Calendar::periodJson(...),
                Calendar::createPeriod(...), Calendar::updatePeriod(...), false],
        ];
    }

    private static function resource(Request $request): array
    {
        return self::resources()[$request->route('resource')];
    }

    public function index(Request $request)
    {
        return Api::ok(self::resource($request)[0]());
    }

    public function show(Request $request, string $key)
    {
        [, $get, $json, , , $byCode] = self::resource($request);

        return Api::ok($json($get($byCode ? $key : Api::id($key))));
    }

    public function store(Request $request)
    {
        [, , , $create, , $byCode] = self::resource($request);
        $new = $create(Body::from($request));

        return Api::created($byCode ? ['code' => $new] : ['id' => $new]);
    }

    public function update(Request $request, string $key)
    {
        [, , , , $update, $byCode] = self::resource($request);
        $key = $byCode ? $key : Api::id($key);
        $update($key, Body::from($request));

        return Api::noContent();
    }
}
