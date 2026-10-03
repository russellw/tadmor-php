<?php

namespace App\Services;

use App\Errors\ApiError;
use App\Support\Dec;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Applying payments and credit notes to open documents (domain §5, §7.3).
 *
 * Auto-apply spreads a settling document's unapplied remainder across the
 * party's posted documents in the same currency, oldest first. An
 * application posts nothing, except when the two documents' rates value the
 * applied amount differently in the base currency: then an FX entry moves
 * the difference between the control account and the FX gain/loss account.
 * The schema refuses over-application and mismatched parties or currencies.
 */
final class Settlement
{
    /** Everything applied to a document, from payments and credit notes alike. */
    private static function settled(DocKind $target, int $docId): BigDecimal
    {
        $total = BigDecimal::zero();
        foreach (Kinds::settlers($target->sales) as $settler) {
            $total = $total->plus(Dec::of(DB::table($settler->applications)->where($target->key, $docId)->value(DB::raw('COALESCE(sum(amount_applied), 0)'))));
        }

        return $total;
    }

    public static function apply(string $collection, int $id): array
    {
        $kind = Kinds::$payments[$collection] ?? Kinds::document($collection);
        $payment = $kind instanceof PaymentKind;
        $target = $payment ? $kind->documents : Kinds::settled($kind->sales);
        $amountField = $payment ? 'amount' : 'total';
        $dateField = $payment ? 'payment_date' : $kind->date;

        $settler = DB::table($kind->table)->where('id', $id)->lockForUpdate()->first()
            ?? throw new ApiError(404, "{$kind->noun} not found");
        if ($settler->status !== 'posted') {
            throw new ApiError(409, "the {$kind->noun} is not posted");
        }
        $applied = Dec::of(DB::table($kind->applications)->where($kind->key, $id)->value(DB::raw('COALESCE(sum(amount_applied), 0)')));
        $remaining = Dec::of($settler->$amountField)->minus($applied);

        $openDocs = DB::table($target->table)
            ->where($kind->partyId, $settler->{$kind->partyId})
            ->where('currency_code', $settler->currency_code)->where('status', 'posted')
            ->orderBy($target->date)->orderBy('id')->lockForUpdate()->get();
        $created = [];
        foreach ($openDocs as $doc) {
            if (! $remaining->isPositive()) {
                break;
            }
            $available = Dec::of($doc->total)->minus(self::settled($target, $doc->id));
            if (! $available->isPositive()) {
                continue;
            }
            $amount = Dec::min($available, $remaining);
            $appId = DB::table($kind->applications)->insertGetId([
                $kind->key => $id, $target->key => $doc->id, 'amount_applied' => $amount->toString(),
            ]);
            $created[] = [$appId, $amount, $doc];
            $remaining = $remaining->minus($amount);
        }

        self::postFx($kind, $settler, $settler->$dateField, $target, $created);

        return array_map(fn ($c) => ['document_id' => $c[2]->id, 'amount_applied' => Dec::fmt4($c[1])], $created);
    }

    /** Post the realized FX entry for each application that needs one. */
    private static function postFx(PaymentKind|DocKind $kind, object $settler, string $date, DocKind $target, array $created): void
    {
        if (! $created) {
            return;
        }
        $rates = DB::table('journal_entries')
            ->whereIn('id', [$settler->journal_entry_id, ...array_map(fn ($c) => $c[2]->journal_entry_id, $created)])
            ->pluck('exchange_rate', 'id');
        $settlerRate = Dec::of($rates[$settler->journal_entry_id]);
        $control = DB::table($kind->partyTable)->where('id', $settler->{$kind->partyId})->value($kind->controlAccount);
        $settings = Master::settings();
        foreach ($created as [$appId, $amount, $doc]) {
            $diff = Dec::round4($amount->multipliedBy($settlerRate))
                ->minus(Dec::round4($amount->multipliedBy($rates[$doc->journal_entry_id])));
            if ($diff->isZero()) {
                continue;
            }
            if ($settings->fx_gain_loss_account_id === null) {
                throw new ApiError(422, 'a realized exchange difference arises, but no FX gain/loss account is configured');
            }
            // A/R side: a positive difference debits A/R (a gain); A/P mirrors it.
            $v = $kind->sales ? $diff : $diff->negated();
            $lines = [
                [$control, Dec::pos($v), Dec::pos($v->negated()), Dec::pos($v), Dec::pos($v->negated()), 'Settlement revaluation'],
                [$settings->fx_gain_loss_account_id, Dec::pos($v->negated()), Dec::pos($v), Dec::pos($v->negated()), Dec::pos($v),
                    'Exchange gain (loss)'],
            ];
            $number = $doc->{$target->number};
            $entry = Posting::newEntry($date, $settings->base_currency, $lines, memo: "Exchange difference on settlement of {$target->noun} $number",
                reference: $number, rate: BigDecimal::one());
            DB::table($kind->applications)->where('id', $appId)->update(['fx_journal_entry_id' => $entry]);
        }
    }
}
