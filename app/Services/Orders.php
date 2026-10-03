<?php

namespace App\Services;

use App\Errors\ApiError;
use App\Http\Body;
use App\Support\Dates;
use App\Support\Dec;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Sales and purchase orders: lifecycle and fulfilment (domain §6).
 *
 * Orders never post. Fulfilment creates draft documents and stock movements
 * linked back to the order lines, and the fulfilment views derive what has
 * been invoiced, billed, shipped, or received from those links.
 */
final class Orders
{
    private static function query(OrderKind $kind)
    {
        return DB::table($kind->table.' as o')
            ->join($kind->fulfilment.' as f', 'f.order_id', '=', 'o.id')
            ->select('o.*', "f.{$kind->billed}_status", "f.{$kind->moved}_status");
    }

    public static function json(OrderKind $kind, object $o): array
    {
        return [
            'id' => $o->id, 'order_number' => $o->order_number, $kind->partyId => $o->{$kind->partyId},
            'order_date' => $o->order_date, $kind->expectedDate => $o->{$kind->expectedDate},
            'currency_code' => $o->currency_code, 'status' => $o->status, 'total' => Dec::fmt4($o->total),
            "{$kind->billed}_status" => $o->{"{$kind->billed}_status"},
            "{$kind->moved}_status" => $o->{"{$kind->moved}_status"},
            'reference' => $o->reference, 'memo' => $o->memo,
        ];
    }

    public static function all(OrderKind $kind): array
    {
        return self::query($kind)->orderByDesc('o.order_date')->orderByDesc('o.id')->get()
            ->map(fn ($o) => self::json($kind, $o))->all();
    }

    public static function get(OrderKind $kind, int $id): object
    {
        return self::query($kind)->where('o.id', $id)->first() ?? throw new ApiError(404, "{$kind->noun} not found");
    }

    /** Order lines with their fulfilment quantities, in line order (or id order). */
    private static function lineRows(OrderKind $kind, int $id, string $order = 'line_no')
    {
        return DB::table($kind->lines->table.' as l')
            ->join($kind->lineFulfilment.' as f', 'f.order_line_id', '=', 'l.id')
            ->where('l.order_id', $id)->orderBy("l.$order")
            ->select('l.*', "f.qty_{$kind->billed}", "f.qty_{$kind->moved}", "f.qty_to_{$kind->billVerb}", "f.qty_to_{$kind->moveVerb}")
            ->get();
    }

    public static function lines(OrderKind $kind, int $id): array
    {
        self::get($kind, $id);
        $out = [];
        foreach (self::lineRows($kind, $id) as $line) {
            $j = Documents::lineJson($kind, $line);
            $j['order_line_id'] = $line->id;
            foreach (["qty_{$kind->billed}", "qty_{$kind->moved}", "qty_to_{$kind->billVerb}", "qty_to_{$kind->moveVerb}"] as $name) {
                $j[$name] = Dec::fmt4($line->$name);
            }
            $out[] = $j;
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Lifecycle
    // ------------------------------------------------------------------

    private static function lock(OrderKind $kind, int $id): object
    {
        return DB::table($kind->table)->where('id', $id)->lockForUpdate()->first()
            ?? throw new ApiError(404, "{$kind->noun} not found");
    }

    public static function confirm(OrderKind $kind, int $id): void
    {
        $o = self::lock($kind, $id);
        if ($o->status !== 'draft') {
            throw new ApiError(409, "the {$kind->noun} is {$o->status}, not draft");
        }
        if (! DB::table($kind->lines->table)->where('order_id', $id)->exists()) {
            throw new ApiError(422, "the {$kind->noun} has no lines");
        }
        DB::table($kind->table)->where('id', $id)->update(['status' => 'open']);
    }

    public static function close(OrderKind $kind, int $id): void
    {
        $o = self::lock($kind, $id);
        if ($o->status !== 'open') {
            throw new ApiError(409, "the {$kind->noun} is {$o->status}, not open");
        }
        DB::table($kind->table)->where('id', $id)->update(['status' => 'closed']);
    }

    /** Whether anything has been invoiced, billed, shipped, or received against the order. */
    public static function isFulfilled(OrderKind $kind, int $id): bool
    {
        return DB::table($kind->lineFulfilment)->where('order_id', $id)
            ->where(fn ($q) => $q->where("qty_{$kind->billed}", '>', 0)->orWhere("qty_{$kind->moved}", '>', 0))
            ->exists();
    }

    public static function cancel(OrderKind $kind, int $id): void
    {
        $o = self::lock($kind, $id);
        if (! in_array($o->status, ['draft', 'open'], true)) {
            throw new ApiError(409, "the {$kind->noun} is {$o->status}");
        }
        if ($o->status === 'open' && self::isFulfilled($kind, $id)) {
            throw new ApiError(409, "the {$kind->noun} has been partly fulfilled and cannot be cancelled");
        }
        DB::table($kind->table)->where('id', $id)->update(['status' => 'cancelled']);
    }

    // ------------------------------------------------------------------
    // Fulfilment
    // ------------------------------------------------------------------

    /** @return array<int, BigDecimal> order_line_id => quantity; empty means everything */
    private static function requested(Body $b): array
    {
        $out = [];
        foreach ($b->list('lines') as $i => $line) {
            $lineId = $line->int('order_line_id') ?? throw new ApiError(400, 'line '.($i + 1).': order_line_id is required');
            $out[$lineId] = $line->decimal('quantity', Dec::MONEY, Dec::zero());
        }

        return $out;
    }

    private static function quantity(BigDecimal $remaining, array $requested, int $lineId): BigDecimal
    {
        return $requested ? Dec::min($remaining, $requested[$lineId] ?? BigDecimal::zero()) : $remaining;
    }

    private static function openOrder(OrderKind $kind, int $id): object
    {
        $o = self::lock($kind, $id);
        if ($o->status !== 'open') {
            throw new ApiError(409, "the {$kind->noun} is {$o->status}, not open");
        }

        return $o;
    }

    /** Invoice a sales order or bill a purchase order (domain §6.3). */
    public static function invoice(OrderKind $kind, int $id, Body $b, ?int $userId): int
    {
        $doc = $kind->document;
        $number = $b->requiredStr($doc->number);
        $b->requiredStr($doc->date);
        $o = self::openOrder($kind, $id);
        $date = $b->date($doc->date);
        $due = $b->date('due_date');
        if ($due !== null && $due < $date) {
            throw new ApiError(422, "due_date must not be before {$doc->date}");
        }
        $requested = self::requested($b);
        $picked = [];
        foreach (self::lineRows($kind, $id, 'id') as $line) {
            $qty = self::quantity(Dec::of($line->{"qty_to_{$kind->billVerb}"}), $requested, $line->id);
            if ($qty->isPositive()) {
                $picked[] = [$line, $qty];
            }
        }
        if (! $picked) {
            throw new ApiError(422, "nothing is left to {$kind->billVerb} on this {$kind->noun}");
        }
        $header = [$doc->number => $number, $doc->partyId => $o->{$kind->partyId}, $doc->date => $date, 'due_date' => $due,
            'currency_code' => $o->currency_code, 'reference' => $o->order_number];
        Documents::checkNumberFree($doc, $header);
        $newId = DB::table($doc->table)->insertGetId($header + ['created_by' => $userId]);
        $lk = $kind->lines;
        $rows = [];
        foreach ($picked as $n => [$line, $qty]) {
            $rows[] = [
                $doc->lines->parent => $newId, 'line_no' => $n + 1, 'product_id' => $line->product_id,
                'description' => $line->description, 'quantity' => $qty->toString(), 'tax_code' => $line->tax_code,
                'tax_rate' => $line->tax_rate, 'order_line_id' => $line->id,
                $lk->price => $line->{$lk->price}, $lk->account => $line->{$lk->account},
            ];
        }
        DB::table($doc->lines->table)->insert($rows);

        return $newId;
    }

    /** The moving-average unit cost of a product in a warehouse (domain §6.3). */
    private static function avgCost(int $productId, int $warehouseId): BigDecimal
    {
        return Dec::of(DB::table('stock_on_hand')->where(['product_id' => $productId, 'warehouse_id' => $warehouseId])
            ->value('avg_unit_cost') ?? 0);
    }

    /** Ship a sales order or receive a purchase order into draft movements. */
    public static function move(OrderKind $kind, int $id, Body $b, ?int $userId): array
    {
        $warehouse = $b->requiredId('warehouse_id');
        $o = self::openOrder($kind, $id);
        $date = $b->date('movement_date') ?? Dates::today();
        $requested = self::requested($b);
        $rate = $kind->sales ? null : Posting::rateFor($o->currency_code, $date);
        $lines = self::lineRows($kind, $id, 'id');
        $tracked = DB::table('products')->whereIn('id', $lines->pluck('product_id')->filter()->unique())
            ->where('track_inventory', true)->where('is_active', true)->pluck('id')->flip();
        $created = [];
        foreach ($lines as $line) {
            if ($line->product_id === null || ! isset($tracked[$line->product_id])) {
                continue;
            }
            $qty = self::quantity(Dec::of($line->{"qty_to_{$kind->moveVerb}"}), $requested, $line->id);
            if (! $qty->isPositive()) {
                continue;
            }
            [$cost, $signed, $type, $source] = $kind->sales
                ? [self::avgCost($line->product_id, $warehouse), $qty->negated(), 'issue', 'sales_order_line']
                : [Dec::round4(Dec::of($line->unit_cost)->multipliedBy($rate)), $qty, 'receipt', 'purchase_order_line'];
            $created[] = DB::table('stock_movements')->insertGetId([
                'product_id' => $line->product_id, 'warehouse_id' => $warehouse, 'movement_date' => $date,
                'movement_type' => $type, 'quantity' => $signed->toString(), 'unit_cost' => $cost->toString(),
                'source_type' => $source, 'source_id' => $line->id, 'reference' => $b->text('reference'),
                'created_by' => $userId,
            ]);
        }
        if (! $created) {
            throw new ApiError(422, "nothing is left to {$kind->moveVerb} on this {$kind->noun}");
        }

        return $created;
    }
}
