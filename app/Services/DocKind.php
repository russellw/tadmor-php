<?php

namespace App\Services;

final readonly class DocKind
{
    public string $party;

    public string $partyId;

    public string $partyTable;

    public string $controlAccount;

    /** Whether posting debits the party's control account. */
    public bool $controlDebit;

    /** The column naming this document in its lines, balances view, and applications. */
    public string $key;

    public function __construct(
        public string $collection, // URL collection, e.g. "sales-invoices"
        public string $label, // "Invoice", for titles, PDFs, and email subjects
        public string $noun, // "invoice", in messages
        public string $table,
        public string $balances, // the *_balances view
        public LineKind $lines,
        public bool $sales, // customer side (A/R) rather than supplier side (A/P)
        public bool $credit, // a credit note: posts on the opposite sides
        public string $number,
        public string $date,
        public bool $hasDueDate,
        public string $statusField, // "payment_status" or "application_status"
        public string $pdfPrefix,
        public bool $numberPerParty, // bill numbers are the supplier's own, unique per supplier
        public ?string $applications = null, // for credit notes: the applications they make
    ) {
        $this->party = $sales ? 'customer' : 'supplier';
        $this->partyId = $this->party.'_id';
        $this->partyTable = $this->party.'s';
        $this->controlAccount = $sales ? 'ar_account_id' : 'ap_account_id';
        $this->controlDebit = $sales !== $credit;
        $this->key = $lines->parent;
    }

    /** The second date field, if the kind has one. */
    public function secondDate(): ?string
    {
        return $this->hasDueDate ? 'due_date' : null;
    }
}
