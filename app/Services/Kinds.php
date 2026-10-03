<?php

namespace App\Services;

/**
 * Descriptors for the document kinds that share code paths.
 *
 * Invoices, bills, and both kinds of credit note share one lifecycle and one
 * line shape; sales and purchase orders share that line shape too; customer
 * and supplier payments mirror each other. Each kind records the names that
 * differ, so the services are written once (spec/api.md §5.9–5.10).
 */
final class Kinds
{
    /** @var array<string, DocKind> */
    public static array $documents;

    /** @var array<string, OrderKind> */
    public static array $orders;

    /** @var array<string, PaymentKind> */
    public static array $payments;

    public static function document(string $collection): DocKind
    {
        return self::$documents[$collection];
    }

    public static function order(string $collection): OrderKind
    {
        return self::$orders[$collection];
    }

    public static function payment(string $collection): PaymentKind
    {
        return self::$payments[$collection];
    }

    /** The invoices or bills of one side. */
    public static function settled(bool $sales): DocKind
    {
        return self::$documents[$sales ? 'sales-invoices' : 'purchase-bills'];
    }

    /** The payments and credit notes that settle one side's documents. */
    public static function settlers(bool $sales): array
    {
        return $sales
            ? [self::$payments['customer-payments'], self::$documents['sales-credit-notes']]
            : [self::$payments['supplier-payments'], self::$documents['purchase-credit-notes']];
    }

    public static function init(): void
    {
        $sales = ['price' => 'unit_price', 'account' => 'revenue_account_id'];
        $purchase = ['price' => 'unit_cost', 'account' => 'expense_account_id'];

        $invoice = new DocKind('sales-invoices', 'Invoice', 'invoice', 'sales_invoices', 'sales_invoice_balances',
            new LineKind('sales_invoice_lines', 'invoice_id', ...$sales, hasOrderLine: true),
            sales: true, credit: false, number: 'invoice_number', date: 'invoice_date', hasDueDate: true,
            statusField: 'payment_status', pdfPrefix: 'invoice', numberPerParty: false);
        $bill = new DocKind('purchase-bills', 'Bill', 'bill', 'purchase_bills', 'purchase_bill_balances',
            new LineKind('purchase_bill_lines', 'bill_id', ...$purchase, hasOrderLine: true),
            sales: false, credit: false, number: 'bill_number', date: 'bill_date', hasDueDate: true,
            statusField: 'payment_status', pdfPrefix: 'bill', numberPerParty: true);
        $salesCredit = new DocKind('sales-credit-notes', 'Credit Note', 'credit note', 'sales_credit_notes',
            'sales_credit_note_balances', new LineKind('sales_credit_note_lines', 'credit_note_id', ...$sales),
            sales: true, credit: true, number: 'credit_note_number', date: 'credit_note_date', hasDueDate: false,
            statusField: 'application_status', pdfPrefix: 'credit-note', numberPerParty: false,
            applications: 'sales_credit_applications');
        $purchaseCredit = new DocKind('purchase-credit-notes', 'Credit Note', 'supplier credit', 'purchase_credit_notes',
            'purchase_credit_note_balances', new LineKind('purchase_credit_note_lines', 'credit_note_id', ...$purchase),
            sales: false, credit: true, number: 'credit_note_number', date: 'credit_note_date', hasDueDate: false,
            statusField: 'application_status', pdfPrefix: 'supplier-credit', numberPerParty: true,
            applications: 'purchase_credit_applications');
        self::$documents = [];
        foreach ([$invoice, $bill, $salesCredit, $purchaseCredit] as $k) {
            self::$documents[$k->collection] = $k;
        }

        self::$orders = [
            'sales-orders' => new OrderKind('sales-orders', 'Sales Order', 'sales order', 'sales_orders',
                'sales_order_fulfilment', 'sales_order_line_fulfilment',
                new LineKind('sales_order_lines', 'order_id', ...$sales),
                sales: true, expectedDate: 'expected_ship_date', billed: 'invoiced', moved: 'shipped',
                billVerb: 'invoice', moveVerb: 'ship', pdfPrefix: 'sales-order', document: $invoice),
            'purchase-orders' => new OrderKind('purchase-orders', 'Purchase Order', 'purchase order', 'purchase_orders',
                'purchase_order_fulfilment', 'purchase_order_line_fulfilment',
                new LineKind('purchase_order_lines', 'order_id', ...$purchase),
                sales: false, expectedDate: 'expected_receipt_date', billed: 'billed', moved: 'received',
                billVerb: 'bill', moveVerb: 'receive', pdfPrefix: 'purchase-order', document: $bill),
        ];

        self::$payments = [
            'customer-payments' => new PaymentKind('customer-payments', 'customer payment', 'customer_payments',
                'payment_applications', sales: true, cashAccount: 'deposit_account_id', documents: $invoice),
            'supplier-payments' => new PaymentKind('supplier-payments', 'supplier payment', 'supplier_payments',
                'bill_applications', sales: false, cashAccount: 'payment_account_id', documents: $bill),
        ];
    }
}

Kinds::init();
