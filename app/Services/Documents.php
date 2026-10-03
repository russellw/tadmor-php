<?php

namespace App\Services;

use App\Errors\ApiError;
use App\Http\Body;
use App\Support\Dec;
use Illuminate\Support\Facades\DB;

/**
 * Draft documents: create, replace, delete, and read (spec/api.md §5.9–5.10).
 *
 * Invoices, bills, credit notes, and orders share the header-and-lines shape;
 * payments are header only. Line money and header totals are computed by the
 * database (generated columns and triggers), so this class writes only the
 * inputs. A PUT replaces the header and the whole line set.
 */
final class Documents
{
    public const PAYMENT_METHODS = ['cash', 'check', 'card', 'transfer', 'other'];

    // ------------------------------------------------------------------
    // Request bodies
    // ------------------------------------------------------------------

    /** The 400 checks of a header-and-lines body (spec/api.md §5.9). */
    private static function checkRequired(DocKind|OrderKind $kind, Body $b): void
    {
        $b->requiredStr($kind->number);
        $b->requiredId($kind->partyId);
        $b->requiredStr($kind->date);
        $b->requiredStr('currency_code');
        foreach ($b->list('lines') as $i => $line) {
            if (($line->str('description') ?? '') === '') {
                throw new ApiError(400, 'line '.($i + 1).': description is required');
            }
        }
    }

    private static function header(DocKind|OrderKind $kind, Body $b): array
    {
        $fields = [
            $kind->number => $b->str($kind->number),
            $kind->partyId => $b->int($kind->partyId),
            $kind->date => $b->date($kind->date),
            'currency_code' => strtoupper(trim($b->str('currency_code'))),
            'reference' => $b->text('reference'),
            'memo' => $b->text('memo'),
        ];
        $second = $kind->secondDate();
        if ($second !== null) {
            $fields[$second] = $b->date($second);
            if ($fields[$second] !== null && $fields[$second] < $fields[$kind->date]) {
                throw new ApiError(422, "$second must not be before {$kind->date}");
            }
        }

        return $fields;
    }

    /** Parse the line set, checking what the database would refuse. */
    private static function lines(DocKind|OrderKind $kind, Body $b): array
    {
        $lk = $kind->lines;
        $order = $kind instanceof OrderKind;
        $out = [];
        foreach ($b->list('lines') as $i => $line) {
            $n = $i + 1;
            $qty = $line->decimal('quantity', Dec::MONEY, Dec::of(1));
            $price = $line->decimal($lk->price, Dec::MONEY, Dec::zero());
            $rate = $line->decimal('tax_rate', Dec::RATE, Dec::zero());
            if ($qty->isZero() || ($order && $qty->isNegative())) {
                throw new ApiError(422, "line $n: quantity must be ".($order ? 'greater than' : 'other than').' zero');
            }
            if ($rate->isNegative()) {
                throw new ApiError(422, "line $n: tax_rate must not be negative");
            }
            $subtotal = Dec::checkMagnitude(Dec::round4($qty->multipliedBy($price)), "line $n subtotal");
            $tax = Dec::round4($qty->multipliedBy($price)->multipliedBy($rate)->multipliedBy('0.01'));
            Dec::checkMagnitude($subtotal->plus($tax), "line $n total");
            $out[] = [
                'line_no' => $n,
                'product_id' => $line->int('product_id'),
                'description' => $line->str('description'),
                'quantity' => $qty->toString(),
                $lk->price => $price->toString(),
                $lk->account => $line->int($lk->account),
                'tax_code' => $line->text('tax_code'),
                'tax_rate' => $rate->toString(),
            ];
        }

        return $out;
    }

    private static function insertLines(DocKind|OrderKind $kind, int $docId, array $lines): void
    {
        if ($lines) {
            DB::table($kind->lines->table)->insert(array_map(fn ($l) => [$kind->lines->parent => $docId] + $l, $lines));
        }
    }

    public static function checkNumberFree(DocKind|OrderKind $kind, array $fields, ?int $id = null): void
    {
        $number = $kind->number;
        $rows = DB::table($kind->table)->where($number, $fields[$number]);
        if ($kind instanceof DocKind && $kind->numberPerParty) {
            $rows->where($kind->partyId, $fields[$kind->partyId]);
        }
        if ($id !== null) {
            $rows->where('id', '<>', $id);
        }
        if ($rows->exists()) {
            throw new ApiError(409, "$number \"{$fields[$number]}\" is already used");
        }
    }

    public static function create(DocKind|OrderKind $kind, Body $b, ?int $userId): int
    {
        self::checkRequired($kind, $b);
        $header = self::header($kind, $b);
        $lines = self::lines($kind, $b);
        self::checkNumberFree($kind, $header);
        $id = DB::table($kind->table)->insertGetId($header + ['created_by' => $userId]);
        self::insertLines($kind, $id, $lines);

        return $id;
    }

    /** Lock a document (or payment, or order) that must be a draft. */
    public static function lockDraft(DocKind|OrderKind|PaymentKind $kind, int $id): object
    {
        $doc = DB::table($kind->table)->where('id', $id)->lockForUpdate()->first()
            ?? throw new ApiError(404, "{$kind->noun} not found");
        if ($doc->status !== 'draft') {
            throw new ApiError(409, "{$kind->noun} is {$doc->status}, not draft");
        }

        return $doc;
    }

    private static function orderLinked(DocKind|OrderKind $kind, int $id): bool
    {
        $lk = $kind->lines;

        return $lk->hasOrderLine
            && DB::table($lk->table)->where($lk->parent, $id)->whereNotNull('order_line_id')->exists();
    }

    public static function update(DocKind|OrderKind $kind, int $id, Body $b): void
    {
        self::checkRequired($kind, $b);
        self::lockDraft($kind, $id);
        if (self::orderLinked($kind, $id)) {
            throw new ApiError(409, "this {$kind->noun} was produced from an order and cannot be edited");
        }
        $header = self::header($kind, $b);
        $lines = self::lines($kind, $b);
        self::checkNumberFree($kind, $header, $id);
        DB::table($kind->table)->where('id', $id)->update($header);
        DB::table($kind->lines->table)->where($kind->lines->parent, $id)->delete();
        self::insertLines($kind, $id, $lines);
    }

    public static function delete(DocKind|OrderKind $kind, int $id): void
    {
        self::lockDraft($kind, $id);
        DB::table($kind->lines->table)->where($kind->lines->parent, $id)->delete();
        DB::table($kind->table)->where('id', $id)->delete();
    }

    // ------------------------------------------------------------------
    // Reads: invoices, bills, credit notes
    // ------------------------------------------------------------------

    /** Documents joined to their balances view, as d.* plus the balance columns. */
    private static function query(DocKind $kind)
    {
        return DB::table($kind->table.' as d')
            ->join($kind->balances.' as b', "b.{$kind->key}", '=', 'd.id')
            ->select('d.*', 'b.amount_applied', 'b.balance', "b.{$kind->statusField}");
    }

    public static function documentJson(DocKind $kind, object $d): array
    {
        $out = [
            'id' => $d->id, $kind->number => $d->{$kind->number}, $kind->partyId => $d->{$kind->partyId},
            $kind->date => $d->{$kind->date},
        ];
        if ($kind->hasDueDate) {
            $out['due_date'] = $d->due_date;
        }

        return $out + [
            $kind->statusField => $d->{$kind->statusField},
            'currency_code' => $d->currency_code, 'status' => $d->status, 'total' => Dec::fmt4($d->total),
            'amount_applied' => Dec::fmt4($d->amount_applied), 'balance' => Dec::fmt4($d->balance),
            'journal_entry_id' => $d->journal_entry_id, 'reference' => $d->reference, 'memo' => $d->memo,
        ];
    }

    public static function all(DocKind $kind): array
    {
        return self::query($kind)->orderByDesc("d.{$kind->date}")->orderByDesc('d.id')->get()
            ->map(fn ($d) => self::documentJson($kind, $d))->all();
    }

    public static function get(DocKind $kind, int $id): object
    {
        return self::query($kind)->where('d.id', $id)->first() ?? throw new ApiError(404, "{$kind->noun} not found");
    }

    public static function lineJson(DocKind|OrderKind $kind, object $line): array
    {
        $lk = $kind->lines;
        $out = [
            'line_no' => $line->line_no, 'product_id' => $line->product_id, 'description' => $line->description,
            'quantity' => Dec::fmt4($line->quantity), $lk->price => Dec::fmt4($line->{$lk->price}),
            'tax_code' => $line->tax_code, 'tax_rate' => Dec::fmt4($line->tax_rate),
            'line_subtotal' => Dec::fmt4($line->line_subtotal), 'tax_amount' => Dec::fmt4($line->tax_amount),
            'line_total' => Dec::fmt4($line->line_total), $lk->account => $line->{$lk->account},
        ];
        if ($kind instanceof DocKind) {
            $out['order_line_id'] = $lk->hasOrderLine ? $line->order_line_id : null;
        }

        return $out;
    }

    /** A document's line rows, in line order. */
    public static function lineRows(DocKind|OrderKind $kind, int $id)
    {
        return DB::table($kind->lines->table)->where($kind->lines->parent, $id)->orderBy('line_no')->get();
    }

    public static function documentLines(DocKind $kind, int $id): array
    {
        self::get($kind, $id);

        return self::lineRows($kind, $id)->map(fn ($l) => self::lineJson($kind, $l))->all();
    }

    /** Applications made by a settler, in creation order, naming the documents they settle. */
    private static function applicationsJson(string $table, string $settlerColumn, int $id, DocKind $target): array
    {
        return DB::table("$table as a")
            ->join("{$target->table} as d", 'd.id', '=', "a.{$target->key}")
            ->where("a.$settlerColumn", $id)->orderBy('a.id')
            ->get(["a.{$target->key} as document_id", "d.{$target->number} as document_number", 'a.amount_applied'])
            ->map(fn ($a) => [
                'document_id' => $a->document_id, 'document_number' => $a->document_number,
                'amount_applied' => Dec::fmt4($a->amount_applied),
            ])->all();
    }

    public static function creditNoteApplications(DocKind $kind, int $id): array
    {
        self::get($kind, $id);

        return self::applicationsJson($kind->applications, $kind->key, $id, Kinds::settled($kind->sales));
    }

    /** What has been applied to an invoice or bill, by payments and credit notes (for the UI). */
    public static function applicationsTo(DocKind $kind, int $id): array
    {
        [$pay, $note] = Kinds::settlers($kind->sales);
        $out = [];
        foreach (DB::table("{$pay->applications} as a")->join("{$pay->table} as p", 'p.id', '=', 'a.payment_id')
            ->where("a.{$kind->key}", $id)->orderBy('a.id')->get(['p.id', 'p.payment_date', 'p.status', 'a.amount_applied']) as $a) {
            $out[] = ['collection' => $pay->collection, 'id' => $a->id, 'label' => "Payment {$a->id}",
                'date' => $a->payment_date, 'status' => $a->status, 'amount_applied' => Dec::fmt4($a->amount_applied)];
        }
        foreach (DB::table("{$note->applications} as a")->join("{$note->table} as n", 'n.id', '=', 'a.credit_note_id')
            ->where("a.{$kind->key}", $id)->orderBy('a.id')
            ->get(['n.id', 'n.credit_note_number', 'n.credit_note_date', 'n.status', 'a.amount_applied']) as $a) {
            $out[] = ['collection' => $note->collection, 'id' => $a->id, 'label' => "Credit note {$a->credit_note_number}",
                'date' => $a->credit_note_date, 'status' => $a->status, 'amount_applied' => Dec::fmt4($a->amount_applied)];
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Payments
    // ------------------------------------------------------------------

    private static function paymentRequired(PaymentKind $kind, Body $b): void
    {
        $b->requiredId($kind->partyId);
        $b->requiredStr('payment_date');
        $b->requiredStr('currency_code');
        $b->requiredStr('amount');
    }

    private static function paymentFields(PaymentKind $kind, Body $b): array
    {
        $amount = $b->decimal('amount');
        if (! $amount->isPositive()) {
            throw new ApiError(422, 'amount must be greater than 0');
        }
        $method = $b->text('method');
        if ($method !== null && ! in_array($method, self::PAYMENT_METHODS, true)) {
            throw new ApiError(422, 'method must be one of '.implode(', ', self::PAYMENT_METHODS));
        }

        return [
            $kind->partyId => $b->int($kind->partyId),
            'payment_date' => $b->date('payment_date'),
            'currency_code' => strtoupper(trim($b->str('currency_code'))),
            'amount' => $amount->toString(),
            'method' => $method,
            'reference' => $b->text('reference'),
            $kind->cashAccount => $b->int($kind->cashAccount),
        ];
    }

    public static function createPayment(PaymentKind $kind, Body $b, ?int $userId): int
    {
        self::paymentRequired($kind, $b);

        return DB::table($kind->table)->insertGetId(self::paymentFields($kind, $b) + ['created_by' => $userId]);
    }

    public static function updatePayment(PaymentKind $kind, int $id, Body $b): void
    {
        self::paymentRequired($kind, $b);
        self::lockDraft($kind, $id);
        DB::table($kind->table)->where('id', $id)->update(self::paymentFields($kind, $b));
    }

    public static function deletePayment(PaymentKind $kind, int $id): void
    {
        self::lockDraft($kind, $id);
        DB::table($kind->table)->where('id', $id)->delete();
    }

    private static function paymentQuery(PaymentKind $kind)
    {
        return DB::table($kind->table.' as p')->select('p.*')->selectSub(
            DB::table($kind->applications)->selectRaw('COALESCE(sum(amount_applied), 0)')->whereColumn('payment_id', 'p.id'),
            'applied',
        );
    }

    public static function paymentJson(PaymentKind $kind, object $p): array
    {
        return [
            'id' => $p->id, $kind->partyId => $p->{$kind->partyId}, 'payment_date' => $p->payment_date,
            $kind->cashAccount => $p->{$kind->cashAccount}, 'currency_code' => $p->currency_code,
            'amount' => Dec::fmt4($p->amount), 'method' => $p->method, 'reference' => $p->reference, 'status' => $p->status,
            'amount_applied' => Dec::fmt4($p->applied), 'unapplied' => Dec::fmt4(Dec::of($p->amount)->minus($p->applied)),
            'journal_entry_id' => $p->journal_entry_id,
        ];
    }

    public static function payments(PaymentKind $kind): array
    {
        return self::paymentQuery($kind)->orderByDesc('p.payment_date')->orderByDesc('p.id')->get()
            ->map(fn ($p) => self::paymentJson($kind, $p))->all();
    }

    public static function payment(PaymentKind $kind, int $id): object
    {
        return self::paymentQuery($kind)->where('p.id', $id)->first() ?? throw new ApiError(404, "{$kind->noun} not found");
    }

    public static function paymentApplications(PaymentKind $kind, int $id): array
    {
        self::payment($kind, $id);

        return self::applicationsJson($kind->applications, $kind->key, $id, $kind->documents);
    }
}
