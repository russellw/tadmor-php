<?php

namespace App\Services;

final readonly class LineKind
{
    public function __construct(
        public string $table,
        public string $parent, // the line's FK to its document, e.g. "invoice_id"
        public string $price, // "unit_price" or "unit_cost"
        public string $account, // "revenue_account_id" or "expense_account_id"
        public bool $hasOrderLine = false,
    ) {}
}
