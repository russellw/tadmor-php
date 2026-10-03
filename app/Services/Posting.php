<?php

namespace App\Services;

use App\Errors\ApiError;
use App\Support\Dec;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Posting to the general ledger, and reversing it (domain §4, §7).
 *
 * Each posting creates one posted journal entry in the document's currency,
 * at the entry's exchange rate, with every line carrying both its
 * transaction and its base amounts. The schema checks that a posted entry
 * balances in both and touches only postable, active accounts in an open
 * period; the checks here come first so that each refusal has the status
 * and message the spec gives it (domain §4.2). The request's transaction
 * (App\Http\Middleware\Transaction) makes each posting all or nothing.
 */
final class Posting
{
    /** The exchange rate a posting in a currency on a date uses (domain §7.1). */
    public static function rateFor(string $currency, string $date): BigDecimal
    {
        if ($currency === Master::baseCurrency()) {
            return BigDecimal::one();
        }
        $rate = DB::table('exchange_rates')->where('currency_code', $currency)->where('rate_date', '<=', $date)
            ->orderByDesc('rate_date')->value('rate')
            ?? throw new ApiError(422, "no $currency exchange rate on or before $date");

        return Dec::of($rate);
    }

    /**
     * Write a posted journal entry. Each line is [account_id, debit, credit,
     * base_debit, base_credit, memo] with BigDecimal amounts.
     */
    public static function newEntry(
        string $date, string $currency, array $lines, ?string $memo = null, ?string $reference = null,
        ?BigDecimal $rate = null, ?int $reverses = null, bool $isClosing = false, ?object $period = null,
    ): int {
        $period ??= Calendar::periodForPosting($date);
        $rate ??= self::rateFor($currency, $date);
        $id = DB::table('journal_entries')->insertGetId([
            'entry_date' => $date, 'period_id' => $period->id, 'currency_code' => $currency,
            'exchange_rate' => $rate->toString(), 'memo' => $memo, 'reference' => $reference, 'status' => 'posted',
            'posted_at' => DB::raw('now()'), 'reverses_entry_id' => $reverses, 'is_closing' => $isClosing,
        ]);
        $rows = [];
        foreach (array_values($lines) as $i => [$account, $debit, $credit, $baseDebit, $baseCredit, $lineMemo]) {
            $rows[] = [
                'journal_entry_id' => $id, 'line_no' => $i + 1, 'account_id' => $account,
                'debit' => $debit->toString(), 'credit' => $credit->toString(),
                'base_debit' => $baseDebit->toString(), 'base_credit' => $baseCredit->toString(), 'memo' => $lineMemo,
            ];
        }
        DB::table('journal_lines')->insert($rows);

        return $id;
    }

    /** A line for a signed amount on its natural side; a negative amount goes on the opposite side (domain §4.3). */
    private static function side(BigDecimal $amount, BigDecimal $base, bool $debit, string $memo, int $account): array
    {
        $zero = BigDecimal::zero();
        if ($amount->isPositive() === $debit) {
            return [$account, $amount->abs(), $zero, $base->abs(), $zero, $memo];
        }

        return [$account, $zero, $amount->abs(), $zero, $base->abs(), $memo];
    }

    // ------------------------------------------------------------------
    // Invoices, bills, credit notes
    // ------------------------------------------------------------------

    public static function postDocument(DocKind $kind, int $id): int
    {
        $doc = DB::table($kind->table)->where('id', $id)->lockForUpdate()->first()
            ?? throw new ApiError(404, "{$kind->noun} not found");
        if ($doc->status !== 'draft') {
            throw new ApiError(409, "{$kind->noun} is {$doc->status}, not draft");
        }
        $total = Dec::of($doc->total);
        if (! $total->isPositive()) {
            throw new ApiError(422, "{$kind->noun} total must be greater than zero");
        }
        $control = DB::table($kind->partyTable)->where('id', $doc->{$kind->partyId})->value($kind->controlAccount)
            ?? throw new ApiError(422, "the {$kind->party} has no ".($kind->sales ? 'A/R' : 'A/P').' control account');

        $lk = $kind->lines;
        $lines = DB::table($lk->table)->where($lk->parent, $id)->orderBy('line_no')->get();
        $fallback = $kind->sales ? 'revenue_account_id' : 'inventory_account_id';
        $products = DB::table('products')->whereIn('id', $lines->pluck('product_id')->filter()->unique())
            ->pluck($fallback, 'id');
        $taxes = DB::table('tax_codes')->whereIn('code', $lines->pluck('tax_code')->filter()->unique())
            ->pluck('tax_account_id', 'code');

        $detail = [];
        $tax = [];
        foreach ($lines as $line) {
            $account = $line->{$lk->account} ?? ($line->product_id ? $products[$line->product_id] ?? null : null);
            $subtotal = Dec::of($line->line_subtotal);
            if (! $subtotal->isZero()) {
                if ($account === null) {
                    throw new ApiError(422, "line {$line->line_no} has no ".($kind->sales ? 'revenue' : 'expense').' account');
                }
                $detail[$account] = ($detail[$account] ?? BigDecimal::zero())->plus($subtotal);
            }
            $taxAmount = Dec::of($line->tax_amount);
            if (! $taxAmount->isZero()) {
                $taxAccount = $line->tax_code !== null ? $taxes[$line->tax_code] ?? null : null;
                if ($taxAccount === null) {
                    throw new ApiError(422, "line {$line->line_no} is taxed but its tax code has no tax account");
                }
                $tax[$taxAccount] = ($tax[$taxAccount] ?? BigDecimal::zero())->plus($taxAmount);
            }
        }

        $date = $doc->{$kind->date};
        $period = Calendar::periodForPosting($date);
        $rate = self::rateFor($doc->currency_code, $date);

        // Detail lines sit opposite the control line.
        $detailDebit = ! $kind->controlDebit;
        $entries = [];
        $groups = [[$kind->sales ? 'Revenue' : 'Expense', $detail], [$kind->sales ? 'Sales tax' : 'Input tax', $tax]];
        foreach ($groups as [$memo, $sums]) {
            ksort($sums);
            foreach ($sums as $account => $amount) {
                if (! $amount->isZero()) {
                    $entries[] = self::side($amount, Dec::round4($amount->multipliedBy($rate)), $detailDebit, $memo, $account);
                }
            }
        }
        // The control line carries the total; its base is the net of the details' (domain §7.2).
        $baseNet = BigDecimal::zero();
        foreach ($entries as $e) {
            $baseNet = $kind->controlDebit ? $baseNet->plus($e[4])->minus($e[3]) : $baseNet->plus($e[3])->minus($e[4]);
        }
        $zero = BigDecimal::zero();
        $controlMemo = $kind->sales ? 'Accounts receivable' : 'Accounts payable';
        $controlLine = $kind->controlDebit
            ? [$control, $total, $zero, $baseNet, $zero, $controlMemo]
            : [$control, $zero, $total, $zero, $baseNet, $controlMemo];

        $number = $doc->{$kind->number};
        $entry = self::newEntry($date, $doc->currency_code, [$controlLine, ...$entries], memo: "{$kind->label} $number",
            reference: $number, rate: $rate, period: $period);
        DB::table($kind->table)->where('id', $id)->update(['status' => 'posted', 'journal_entry_id' => $entry, 'period_id' => $period->id]);

        return $entry;
    }

    public static function unpostDocument(DocKind $kind, int $id): int
    {
        $doc = DB::table($kind->table)->where('id', $id)->lockForUpdate()->first()
            ?? throw new ApiError(404, "{$kind->noun} not found");
        if ($doc->status !== 'posted' || $doc->journal_entry_id === null) {
            throw new ApiError(409, "{$kind->noun} is not posted");
        }
        if ($kind->credit) {
            if (DB::table($kind->applications)->where($kind->key, $id)->exists()) {
                throw new ApiError(409, "the {$kind->noun} has been applied and cannot be unposted");
            }
        } elseif (self::appliedTo($kind, $id)) {
            throw new ApiError(409, "payments or credit notes are applied to this {$kind->noun}; it cannot be unposted");
        }
        $reversal = self::reverseEntry($doc->journal_entry_id);
        DB::table($kind->table)->where('id', $id)->update(['status' => 'draft', 'journal_entry_id' => null, 'period_id' => null]);

        return $reversal;
    }

    private static function appliedTo(DocKind $kind, int $id): bool
    {
        foreach (Kinds::settlers($kind->sales) as $settler) {
            if (DB::table($settler->applications)->where($kind->key, $id)->exists()) {
                return true;
            }
        }

        return false;
    }

    /** Post the mirror of an entry: same date, currency, and rate, every line's sides swapped (domain §4.4). */
    public static function reverseEntry(int $entryId): int
    {
        $original = DB::table('journal_entries')->where('id', $entryId)->first();
        if (DB::table('journal_entries')->where('reverses_entry_id', $entryId)->exists()) {
            throw new ApiError(409, "journal entry $entryId is already reversed");
        }
        if (DB::table('bank_statement_lines as b')->join('journal_lines as jl', 'jl.id', '=', 'b.journal_line_id')
            ->where('jl.journal_entry_id', $entryId)->exists()) {
            throw new ApiError(409, "journal entry $entryId has lines matched on a bank statement");
        }
        $lines = DB::table('journal_lines')->where('journal_entry_id', $entryId)->orderBy('line_no')->get()
            ->map(fn ($l) => [$l->account_id, Dec::of($l->credit), Dec::of($l->debit), Dec::of($l->base_credit),
                Dec::of($l->base_debit), $l->memo])->all();

        return self::newEntry($original->entry_date, $original->currency_code, $lines,
            memo: "Reversal of journal entry $entryId", reference: $original->reference,
            rate: Dec::of($original->exchange_rate), reverses: $entryId, isClosing: $original->is_closing);
    }

    // ------------------------------------------------------------------
    // Payments
    // ------------------------------------------------------------------

    public static function postPayment(PaymentKind $kind, int $id): int
    {
        $p = DB::table($kind->table)->where('id', $id)->lockForUpdate()->first()
            ?? throw new ApiError(404, "{$kind->noun} not found");
        if ($p->status !== 'draft') {
            throw new ApiError(409, "{$kind->noun} is {$p->status}, not draft");
        }
        $amount = Dec::of($p->amount);
        if (! $amount->isPositive()) {
            throw new ApiError(422, 'amount must be greater than zero');
        }
        $cash = $p->{$kind->cashAccount}
            ?? throw new ApiError(422, 'the payment has no '.($kind->sales ? 'deposit' : 'payment').' account');
        $party = $kind->sales ? 'customer' : 'supplier';
        $control = DB::table($kind->partyTable)->where('id', $p->{$kind->partyId})->value($kind->controlAccount)
            ?? throw new ApiError(422, "the $party has no ".($kind->sales ? 'A/R' : 'A/P').' control account');
        $period = Calendar::periodForPosting($p->payment_date);
        $rate = self::rateFor($p->currency_code, $p->payment_date);
        $base = Dec::round4($amount->multipliedBy($rate));
        $zero = BigDecimal::zero();
        $lines = $kind->sales
            ? [[$cash, $amount, $zero, $base, $zero, 'Cash received'], [$control, $zero, $amount, $zero, $base, 'Accounts receivable']]
            : [[$control, $amount, $zero, $base, $zero, 'Accounts payable'], [$cash, $zero, $amount, $zero, $base, 'Cash paid']];
        $entry = self::newEntry($p->payment_date, $p->currency_code, $lines,
            memo: $kind->sales ? 'Customer payment' : 'Supplier payment', rate: $rate, period: $period);
        DB::table($kind->table)->where('id', $id)->update(['status' => 'posted', 'journal_entry_id' => $entry, 'period_id' => $period->id]);

        return $entry;
    }

    public static function unpostPayment(PaymentKind $kind, int $id): int
    {
        $p = DB::table($kind->table)->where('id', $id)->lockForUpdate()->first()
            ?? throw new ApiError(404, "{$kind->noun} not found");
        if ($p->status !== 'posted' || $p->journal_entry_id === null) {
            throw new ApiError(409, "{$kind->noun} is not posted");
        }
        $reversal = self::reverseEntry($p->journal_entry_id);
        $apps = DB::table($kind->applications)->where('payment_id', $id);
        foreach ((clone $apps)->whereNotNull('fx_journal_entry_id')->pluck('fx_journal_entry_id') as $fx) {
            self::reverseEntry($fx);
        }
        $apps->delete();
        DB::table($kind->table)->where('id', $id)->update(['status' => 'draft', 'journal_entry_id' => null, 'period_id' => null]);

        return $reversal;
    }

    // ------------------------------------------------------------------
    // Stock movements
    // ------------------------------------------------------------------

    public static function postMovement(int $id, ?int $creditAccountId): int
    {
        $sm = DB::table('stock_movements')->where('id', $id)->lockForUpdate()->first()
            ?? throw new ApiError(404, 'stock movement not found');
        if ($sm->journal_entry_id !== null) {
            throw new ApiError(409, 'stock movement is already posted');
        }
        if (! in_array($sm->movement_type, ['receipt', 'issue'], true)) {
            throw new ApiError(422, "{$sm->movement_type} movements do not post to the ledger");
        }
        $totalCost = Dec::of($sm->total_cost);
        if ($totalCost->isZero()) {
            throw new ApiError(422, 'the movement has no cost to post');
        }
        $product = Master::product($sm->product_id);
        $cost = $totalCost->abs();
        $zero = BigDecimal::zero();
        if ($sm->movement_type === 'issue') {
            if ($product->cogs_account_id === null || $product->inventory_account_id === null) {
                throw new ApiError(422, 'the product needs both a COGS and an inventory account');
            }
            $lines = [[$product->cogs_account_id, $cost, $zero, $cost, $zero, 'Cost of goods sold'],
                [$product->inventory_account_id, $zero, $cost, $zero, $cost, 'Inventory']];
            $memo = 'Inventory issue';
        } else {
            if ($product->inventory_account_id === null) {
                throw new ApiError(422, 'the product has no inventory account');
            }
            if (! Master::isPostable($creditAccountId)) {
                throw new ApiError(422, 'credit_account_id must name a postable, active account');
            }
            $lines = [[$product->inventory_account_id, $cost, $zero, $cost, $zero, 'Inventory'],
                [$creditAccountId, $zero, $cost, $zero, $cost, 'Goods received not invoiced']];
            $memo = 'Inventory receipt';
        }
        $period = Calendar::periodForPosting($sm->movement_date);
        $entry = self::newEntry($sm->movement_date, Master::baseCurrency(), $lines, memo: $memo,
            rate: BigDecimal::one(), period: $period);
        DB::table('stock_movements')->where('id', $id)->update(['journal_entry_id' => $entry, 'period_id' => $period->id]);

        return $entry;
    }

    public static function unpostMovement(int $id): int
    {
        $sm = DB::table('stock_movements')->where('id', $id)->lockForUpdate()->first()
            ?? throw new ApiError(404, 'stock movement not found');
        if ($sm->journal_entry_id === null) {
            throw new ApiError(409, 'stock movement is not posted');
        }
        $reversal = self::reverseEntry($sm->journal_entry_id);
        DB::table('stock_movements')->where('id', $id)->update(['journal_entry_id' => null, 'period_id' => null]);

        return $reversal;
    }
}
