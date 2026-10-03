<?php

namespace App\Services;

final readonly class OrderKind
{
    public string $partyId;

    public string $partyTable;

    public string $number;

    public string $date;

    public function __construct(
        public string $collection,
        public string $label,
        public string $noun,
        public string $table,
        public string $fulfilment, // header statuses view
        public string $lineFulfilment,
        public LineKind $lines,
        public bool $sales,
        public string $expectedDate,
        public string $billed, // "invoiced" or "billed"
        public string $moved, // "shipped" or "received"
        public string $billVerb, // "invoice" or "bill"
        public string $moveVerb, // "ship" or "receive"
        public string $pdfPrefix,
        public DocKind $document, // what invoicing or billing produces
    ) {
        $this->partyId = ($sales ? 'customer' : 'supplier').'_id';
        $this->partyTable = ($sales ? 'customer' : 'supplier').'s';
        $this->number = 'order_number';
        $this->date = 'order_date';
    }

    public function secondDate(): ?string
    {
        return $this->expectedDate;
    }
}
