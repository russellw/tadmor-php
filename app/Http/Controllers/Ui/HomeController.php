<?php

namespace App\Http\Controllers\Ui;

use App\Support\Dates;
use Illuminate\Support\Facades\DB;

/** The home page (domain §13.2, H1–H5). */
class HomeController
{
    /** Posted documents with a positive balance, per currency, with the overdue part (H1). */
    private static function outstanding(string $view): array
    {
        return DB::select(
            "SELECT currency_code, sum(balance) AS total, COALESCE(sum(balance) FILTER (WHERE due_date < ?), 0) AS overdue, count(*) AS n
             FROM $view WHERE status = 'posted' AND balance > 0 GROUP BY currency_code ORDER BY currency_code",
            [Dates::today()],
        );
    }

    public function __invoke()
    {
        $today = Dates::today();
        $overdue = DB::table('sales_invoice_balances as b')->join('customers as c', 'c.id', '=', 'b.customer_id')
            ->join('organizations as o', 'o.id', '=', 'c.organization_id')
            ->where('b.status', 'posted')->where('b.balance', '>', 0)->where('b.due_date', '<', $today)
            ->orderBy('b.due_date')->orderBy('b.invoice_id')->limit(10)
            ->get(['b.invoice_id as id', 'b.invoice_number as number', 'o.name as party', 'b.due_date', 'b.currency_code', 'b.balance']);
        $dueSoon = DB::table('purchase_bill_balances as b')->join('suppliers as s', 's.id', '=', 'b.supplier_id')
            ->join('organizations as o', 'o.id', '=', 's.organization_id')
            ->where('b.status', 'posted')->where('b.balance', '>', 0)
            ->where('b.due_date', '>=', $today)->where('b.due_date', '<=', Dates::addDays($today, 14))
            ->orderBy('b.due_date')->orderBy('b.bill_id')->limit(10)
            ->get(['b.bill_id as id', 'b.bill_number as number', 'o.name as party', 'b.due_date', 'b.currency_code', 'b.balance']);

        return view('home', [
            'title' => 'Home', 'today' => $today,
            'receivables' => self::outstanding('sales_invoice_balances'),
            'payables' => self::outstanding('purchase_bill_balances'),
            'counts' => [
                'sales_orders' => DB::table('sales_orders')->where('status', 'open')->count(),
                'purchase_orders' => DB::table('purchase_orders')->where('status', 'open')->count(),
                'draft_invoices' => DB::table('sales_invoices')->where('status', 'draft')->count(),
                'draft_bills' => DB::table('purchase_bills')->where('status', 'draft')->count(),
            ],
            'overdue' => $overdue, 'dueSoon' => $dueSoon,
        ]);
    }
}
