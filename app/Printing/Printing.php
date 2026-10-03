<?php

namespace App\Printing;

use App\Errors\ApiError;
use App\Services\DocKind;
use App\Services\Documents;
use App\Services\Kinds;
use App\Services\Orders;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Printable documents: one shared A4 layout for invoices, bills, credit notes,
 * and orders (spec/api.md §5.11, domain §11), and emailing them.
 */
final class Printing
{
    private const MARGIN = 54.0;

    private const TOP = 841.89 - 54;

    private const BOTTOM = 72.0;

    private const RIGHT = 595.28 - self::MARGIN;

    private const NUM_X = self::MARGIN;

    private const DESC_X = self::MARGIN + 26;

    private const QTY_X = 360.0;

    private const PRICE_X = 432.0;

    private const TAX_X = 472.0;

    private const DESC_MAX = self::QTY_X - 60 - self::DESC_X;

    private const GRAY = 0.45;

    /** collection => [number label, date label, second-date label, party heading, applied label, balance label] */
    public const LABELS = [
        'sales-invoices' => ['Invoice no.', 'Invoice date', 'Due date', 'BILL TO', 'Amount paid', 'Balance due'],
        'purchase-bills' => ['Bill no.', 'Bill date', 'Due date', 'SUPPLIER', 'Amount paid', 'Balance due'],
        'sales-credit-notes' => ['Credit note no.', 'Credit note date', null, 'CREDIT TO', 'Amount applied', 'Unapplied'],
        'purchase-credit-notes' => ['Credit note no.', 'Credit note date', null, 'SUPPLIER', 'Amount applied', 'Unapplied'],
        'sales-orders' => ['Order no.', 'Order date', 'Expected ship', 'CUSTOMER', null, null],
        'purchase-orders' => ['Order no.', 'Order date', 'Expected receipt', 'SUPPLIER', null, null],
    ];

    private const TITLES = ['purchase-credit-notes' => 'Supplier Credit'];

    /** A party block: name, legal name, tax id, address lines. */
    private static function party(object $org): array
    {
        $lines = [];
        $a = DB::table('addresses')->where('organization_id', $org->id)->orderBy('id')->first();
        if ($a !== null) {
            $lines = array_values(array_filter([$a->line1, $a->line2]));
            $city = implode(', ', array_filter([$a->city, $a->region]));
            if ($a->postal_code) {
                $city = trim("$city {$a->postal_code}");
            }
            if ($city !== '') {
                $lines[] = $city;
            }
            $lines[] = DB::table('countries')->where('code', $a->country_code)->value('name') ?? $a->country_code;
        }

        return ['name' => $org->name, 'legal' => $org->legal_name, 'tax_id' => $org->tax_id, 'address' => $lines];
    }

    /** Gather what the printed form of a document shows. */
    public static function load(string $collection, int $id): array
    {
        [$numberLabel, $dateLabel, $secondLabel, $partyLabel, $appliedLabel, $balanceLabel] = self::LABELS[$collection];
        $kind = Kinds::$documents[$collection] ?? Kinds::order($collection);
        if ($kind instanceof DocKind) {
            $doc = Documents::get($kind, $id);
            $lines = Documents::documentLines($kind, $id);
            $second = $kind->hasDueDate ? $doc->due_date : null;
            [$applied, $balance] = [$doc->amount_applied, $doc->balance];
            $title = self::TITLES[$collection] ?? $kind->label;
        } else {
            $doc = Orders::get($kind, $id);
            $lines = Orders::lines($kind, $id);
            $second = $doc->{$kind->expectedDate};
            [$applied, $balance] = [null, null];
            $title = $kind->label;
        }
        $org = DB::table('organizations')->where('id', DB::table($kind->partyTable)->where('id', $doc->{$kind->partyId})->value('organization_id'))->first();
        $meta = [[$numberLabel, $doc->{$kind->number}], [$dateLabel, $doc->{$kind->date}]];
        if ($secondLabel !== null && $second !== null) {
            $meta[] = [$secondLabel, $second];
        }
        $meta[] = ['Currency', $doc->currency_code];
        $me = DB::table('organizations')->where('is_self', true)->first();
        $unit = $kind->lines->price;

        return [
            'kind' => $title, 'number' => $doc->{$kind->number}, 'status' => $doc->status, 'currency' => $doc->currency_code,
            'meta' => $meta, 'party_label' => $partyLabel, 'party' => self::party($org), 'seller' => $me ? self::party($me) : null,
            'unit_label' => $unit === 'unit_price' ? 'UNIT PRICE' : 'UNIT COST',
            'subtotal' => $doc->subtotal, 'tax_total' => $doc->tax_total, 'total' => $doc->total,
            'applied' => $applied, 'balance' => $balance, 'applied_label' => $appliedLabel, 'balance_label' => $balanceLabel,
            'reference' => $doc->reference, 'memo' => $doc->memo, 'email' => $org->email,
            'lines' => array_map(fn ($l) => [$l['line_no'], $l['description'], $l['quantity'], $l[$unit], $l['tax_rate'], $l['line_subtotal']], $lines),
        ];
    }

    public static function filename(string $collection, string $number): string
    {
        $prefix = (Kinds::$documents[$collection] ?? Kinds::order($collection))->pdfPrefix;

        return $prefix.'-'.preg_replace('/[^A-Za-z0-9._-]/', '-', $number).'.pdf';
    }

    // ------------------------------------------------------------------
    // Layout
    // ------------------------------------------------------------------

    /** 1234.5 -> "1,234.50": grouped, at least two decimals, none lost. */
    public static function amount(string $v): string
    {
        $sign = str_starts_with($v, '-') ? '-' : '';
        [$whole, $frac] = array_pad(explode('.', ltrim($v, '-'), 2), 2, '');
        $frac = str_pad(rtrim($frac, '0'), 2, '0');

        return $sign.number_format((int) $whole).'.'.$frac;
    }

    public static function qty(string $v): string
    {
        return str_contains($v, '.') ? rtrim(rtrim($v, '0'), '.') : $v;
    }

    private static function truncate(string $font, float $size, float $limit, string $s): string
    {
        if (Pdf::width($font, $size, $s) <= $limit) {
            return $s;
        }
        while ($s !== '' && Pdf::width($font, $size, $s.'…') > $limit) {
            $s = mb_substr($s, 0, -1);
        }

        return $s.'…';
    }

    private static function wrap(string $font, float $size, float $limit, string $s): array
    {
        $lines = [];
        $line = '';
        foreach (preg_split('/\s+/', $s, -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $candidate = $line === '' ? $word : "$line $word";
            if ($line !== '' && Pdf::width($font, $size, $candidate) > $limit) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $candidate;
            }
        }

        return $line === '' ? $lines : [...$lines, $line];
    }

    private static function partyText(Pdf $pdf, int $page, float $x, float $y, float $nameSize, array $p): float
    {
        $pdf->text($page, Pdf::BOLD, $nameSize, $x, $y, $p['name']);
        $y -= 13;
        if ($p['legal'] && $p['legal'] !== $p['name']) {
            $pdf->text($page, Pdf::REGULAR, 9, $x, $y, $p['legal'], self::GRAY);
            $y -= 12;
        }
        foreach ($p['address'] as $line) {
            $pdf->text($page, Pdf::REGULAR, 9, $x, $y, $line, self::GRAY);
            $y -= 12;
        }
        if ($p['tax_id']) {
            $pdf->text($page, Pdf::REGULAR, 9, $x, $y, 'Tax ID: '.$p['tax_id'], self::GRAY);
            $y -= 12;
        }

        return $y;
    }

    private static function tableHeader(Pdf $pdf, int $page, float $y, string $unitLabel): float
    {
        $columns = [[self::NUM_X, '#', false], [self::DESC_X, 'DESCRIPTION', false], [self::QTY_X, 'QTY', true],
            [self::PRICE_X, $unitLabel, true], [self::TAX_X, 'TAX %', true], [self::RIGHT, 'AMOUNT', true]];
        foreach ($columns as [$x, $label, $right]) {
            $pdf->text($page, Pdf::BOLD, 8, $x - ($right ? Pdf::width(Pdf::BOLD, 8, $label) : 0), $y, $label, self::GRAY);
        }
        $y -= 6;
        $pdf->line($page, self::MARGIN, $y, self::RIGHT, $y, 0.8, 0.2);

        return $y - 14;
    }

    public static function render(array $d): string
    {
        $pdf = new Pdf;
        $page = $pdf->addPage();
        $title = strtoupper($d['kind']);
        if (in_array($d['status'], ['draft', 'void', 'cancelled'], true)) {
            $title = strtoupper($d['status'])." $title";
        }
        $pdf->text($page, Pdf::BOLD, 20, self::RIGHT - Pdf::width(Pdf::BOLD, 20, $title), self::TOP - 6, $title);

        $metaY = self::TOP - 36;
        foreach ($d['meta'] as [$label, $value]) {
            $pdf->text($page, Pdf::REGULAR, 9, self::RIGHT - 140, $metaY, $label, self::GRAY);
            $pdf->text($page, Pdf::REGULAR, 9, self::RIGHT - Pdf::width(Pdf::REGULAR, 9, $value), $metaY, $value);
            $metaY -= 13;
        }

        $y = self::TOP - 6;
        if ($d['seller']) {
            $y = self::partyText($pdf, $page, self::MARGIN, $y, 11, $d['seller']);
        }
        $y = min($y, $metaY) - 28;
        $pdf->text($page, Pdf::BOLD, 8, self::MARGIN, $y, $d['party_label'], self::GRAY);
        $y = self::partyText($pdf, $page, self::MARGIN, $y - 14, 10, $d['party']) - 24;

        $y = self::tableHeader($pdf, $page, $y, $d['unit_label']);
        foreach ($d['lines'] as [$no, $desc, $q, $unit, $rate, $subtotal]) {
            if ($y < self::BOTTOM + 20) {
                $page = $pdf->addPage();
                $y = self::tableHeader($pdf, $page, self::TOP, $d['unit_label']);
            }
            $pdf->text($page, Pdf::REGULAR, 9, self::NUM_X, $y, (string) $no, self::GRAY);
            $pdf->text($page, Pdf::REGULAR, 9, self::DESC_X, $y, self::truncate(Pdf::REGULAR, 9, self::DESC_MAX, $desc));
            foreach ([[self::QTY_X, self::qty($q)], [self::PRICE_X, self::amount($unit)], [self::TAX_X, self::qty($rate)],
                [self::RIGHT, self::amount($subtotal)]] as [$x, $s]) {
                $pdf->text($page, Pdf::REGULAR, 9, $x - Pdf::width(Pdf::REGULAR, 9, $s), $y, $s);
            }
            $y -= 6;
            $pdf->line($page, self::MARGIN, $y, self::RIGHT, $y, 0.4, 0.9);
            $y -= 12;
        }

        if ($y < self::BOTTOM + 110) {
            $page = $pdf->addPage();
            $y = self::TOP;
        }
        $y -= 8;
        $totalsX = 400.0;
        $total = function (string $label, string $value, string $font) use ($pdf, &$page, &$y, $totalsX) {
            $pdf->text($page, $font, 9, $totalsX, $y, $label);
            $pdf->text($page, $font, 9, self::RIGHT - Pdf::width($font, 9, $value), $y, $value);
            $y -= 14;
        };
        $total('Subtotal', self::amount($d['subtotal']), Pdf::REGULAR);
        $total('Tax', self::amount($d['tax_total']), Pdf::REGULAR);
        $pdf->line($page, $totalsX, $y + 9, self::RIGHT, $y + 9, 0.8, 0.2);
        $y -= 2;
        $total('Total', "{$d['currency']} ".self::amount($d['total']), Pdf::BOLD);
        if ($d['applied_label'] && $d['applied'] !== null && ! \App\Support\Dec::of($d['applied'])->isZero()) {
            $total($d['applied_label'], self::amount($d['applied']), Pdf::REGULAR);
            $total($d['balance_label'], "{$d['currency']} ".self::amount($d['balance']), Pdf::BOLD);
        }

        $noteY = $y - 14;
        if ($d['reference']) {
            $pdf->text($page, Pdf::REGULAR, 9, self::MARGIN, $noteY, 'Reference: '.$d['reference'], self::GRAY);
            $noteY -= 13;
        }
        foreach (self::wrap(Pdf::REGULAR, 9, self::RIGHT - self::MARGIN, $d['memo'] ?? '') as $line) {
            $pdf->text($page, Pdf::REGULAR, 9, self::MARGIN, $noteY, $line, self::GRAY);
            $noteY -= 13;
        }

        $count = $pdf->pageCount();
        for ($i = 0; $i < $count; $i++) {
            $footer = "{$d['kind']} {$d['number']}  ·  Page ".($i + 1)." of $count";
            $pdf->text($i, Pdf::REGULAR, 8, (595.28 - Pdf::width(Pdf::REGULAR, 8, $footer)) / 2, 40, $footer, self::GRAY);
        }

        return $pdf->bytes();
    }

    /** @return array{string, string} the PDF and its filename */
    public static function pdfFor(string $collection, int $id): array
    {
        $d = self::load($collection, $id);

        return [self::render($d), self::filename($collection, $d['number'])];
    }

    // ------------------------------------------------------------------
    // Email
    // ------------------------------------------------------------------

    /** Send a document's PDF to $to, or to the counterparty's address on file. */
    public static function email(string $collection, int $id, array $to): array
    {
        $d = self::load($collection, $id);
        if (! $to) {
            if (! $d['email']) {
                throw new ApiError(422, 'this counterparty has no email address on file; supply a recipient or set one on the organization');
            }
            $to = [$d['email']];
        }
        if (! config('mail.enabled')) {
            throw new ApiError(501, 'email sending is not configured');
        }
        $label = (Kinds::$documents[$collection] ?? Kinds::order($collection))->label;
        $pdf = self::render($d);
        $name = self::filename($collection, $d['number']);
        Mail::raw("Please find attached ".strtolower($label)." {$d['number']}.", function (Message $m) use ($to, $label, $d, $pdf, $name) {
            $m->to($to)->subject("$label {$d['number']}")->attachData($pdf, $name, ['mime' => 'application/pdf']);
        });

        return $to;
    }
}
