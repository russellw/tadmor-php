<?php

namespace App\Services;

use App\Errors\ApiError;
use App\Http\Body;
use App\Support\Dates;
use App\Support\Dec;
use Illuminate\Support\Facades\DB;

/**
 * Stock movements (spec/api.md §5.12, domain §3, §4). Quantity on hand and
 * value are sums over movements. A movement is posted exactly when it
 * carries a journal entry; only receipts and issues post.
 */
final class Stock
{
    public const TYPES = ['receipt', 'issue', 'adjustment', 'transfer_in', 'transfer_out'];

    private const POSITIVE = ['receipt', 'transfer_in'];

    private const NEGATIVE = ['issue', 'transfer_out'];

    public static function json(object $sm): array
    {
        return [
            'id' => $sm->id, 'product_id' => $sm->product_id, 'warehouse_id' => $sm->warehouse_id,
            'movement_date' => $sm->movement_date, 'movement_type' => $sm->movement_type,
            'status' => $sm->journal_entry_id !== null ? 'posted' : 'draft', 'quantity' => Dec::fmt4($sm->quantity),
            'unit_cost' => Dec::fmt4($sm->unit_cost), 'total_cost' => Dec::fmt4($sm->total_cost),
            'reference' => $sm->reference, 'notes' => $sm->notes, 'journal_entry_id' => $sm->journal_entry_id,
            'source_type' => $sm->source_type,
        ];
    }

    public static function all(): array
    {
        return DB::table('stock_movements')->orderByDesc('movement_date')->orderByDesc('id')->get()->map(self::json(...))->all();
    }

    public static function get(int $id): object
    {
        return Master::find('stock_movements', 'id', $id, 'stock movement');
    }

    private static function required(Body $b): void
    {
        $b->requiredId('product_id');
        $b->requiredId('warehouse_id');
        $b->requiredStr('movement_type');
        $b->requiredStr('quantity');
    }

    private static function fields(Body $b): array
    {
        $type = $b->str('movement_type');
        if (! in_array($type, self::TYPES, true)) {
            throw new ApiError(422, 'movement_type must be one of '.implode(', ', self::TYPES));
        }
        $qty = $b->decimal('quantity');
        $cost = $b->decimal('unit_cost', Dec::MONEY, Dec::zero());
        if ($qty->isZero()) {
            throw new ApiError(422, 'quantity must not be zero');
        }
        $positive = in_array($type, self::POSITIVE, true);
        if (($positive && $qty->isNegative()) || (in_array($type, self::NEGATIVE, true) && $qty->isPositive())) {
            throw new ApiError(422, "a $type must have a ".($positive ? 'positive' : 'negative').' quantity');
        }
        if ($cost->isNegative()) {
            throw new ApiError(422, 'unit_cost must not be negative');
        }
        Dec::checkMagnitude(Dec::round4($qty->multipliedBy($cost)), 'total cost');
        $product = DB::table('products')->where('id', $b->int('product_id'))->first()
            ?? throw new ApiError(422, 'unknown product_id');
        if (! $product->track_inventory || ! $product->is_active) {
            throw new ApiError(422, 'the product must be active and inventory-tracked');
        }

        return [
            'product_id' => $product->id, 'warehouse_id' => $b->int('warehouse_id'), 'movement_type' => $type,
            'movement_date' => $b->date('movement_date') ?? Dates::today(), 'quantity' => $qty->toString(),
            'unit_cost' => $cost->toString(), 'reference' => $b->text('reference'), 'notes' => $b->text('notes'),
        ];
    }

    public static function create(Body $b, ?int $userId): int
    {
        self::required($b);

        return DB::table('stock_movements')->insertGetId(self::fields($b) + ['created_by' => $userId]);
    }

    private static function lock(int $id): object
    {
        $sm = DB::table('stock_movements')->where('id', $id)->lockForUpdate()->first()
            ?? throw new ApiError(404, 'stock movement not found');
        if ($sm->journal_entry_id !== null) {
            throw new ApiError(409, 'the stock movement is posted');
        }

        return $sm;
    }

    public static function update(int $id, Body $b): void
    {
        self::required($b);
        $sm = self::lock($id);
        if ($sm->source_type !== null) {
            throw new ApiError(409, 'the stock movement was produced by order fulfilment and cannot be edited');
        }
        DB::table('stock_movements')->where('id', $id)->update(self::fields($b));
    }

    public static function delete(int $id): void
    {
        self::lock($id);
        DB::table('stock_movements')->where('id', $id)->delete();
    }
}
