<?php

namespace App\Services;

final readonly class PaymentKind
{
    public string $partyId;

    public string $partyTable;

    public string $controlAccount;

    /** The column naming the payment in its applications. */
    public string $key;

    public function __construct(
        public string $collection,
        public string $noun,
        public string $table,
        public string $applications,
        public bool $sales,
        public string $cashAccount, // "deposit_account_id" or "payment_account_id"
        public DocKind $documents,
    ) {
        $this->partyId = ($sales ? 'customer' : 'supplier').'_id';
        $this->partyTable = ($sales ? 'customer' : 'supplier').'s';
        $this->controlAccount = $sales ? 'ar_account_id' : 'ap_account_id';
        $this->key = 'payment_id';
    }
}
