<?php

namespace App\Services;

use App\Errors\ApiError;
use App\Http\Body;
use App\Support\Dates;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Fiscal years and accounting periods (spec/api.md §5.7, domain §9.1–9.2). */
final class Calendar
{
    public static function fiscalYearJson(object $y): array
    {
        return ['id' => $y->id, 'name' => $y->name, 'start_date' => $y->start_date, 'end_date' => $y->end_date, 'status' => $y->status];
    }

    public static function periodJson(object $p): array
    {
        return [
            'id' => $p->id, 'fiscal_year_id' => $p->fiscal_year_id, 'name' => $p->name,
            'start_date' => $p->start_date, 'end_date' => $p->end_date, 'status' => $p->status,
        ];
    }

    /** @return array{string, string} */
    private static function dates(Body $b): array
    {
        $start = Dates::parse($b->requiredStr('start_date'), 'start_date');
        $end = Dates::parse($b->requiredStr('end_date'), 'end_date');
        if ($end < $start) {
            throw new ApiError(422, 'end_date must not be before start_date');
        }

        return [$start, $end];
    }

    public static function fiscalYears(): array
    {
        return DB::table('fiscal_years')->orderBy('start_date')->orderBy('id')->get()->map(self::fiscalYearJson(...))->all();
    }

    public static function fiscalYear(int $id): object
    {
        return Master::find('fiscal_years', 'id', $id, 'fiscal year');
    }

    public static function createFiscalYear(Body $b): int
    {
        $name = $b->requiredStr('name');
        $b->requiredStr('start_date');
        $b->requiredStr('end_date');
        [$start, $end] = self::dates($b);

        return DB::table('fiscal_years')->insertGetId(['name' => $name, 'start_date' => $start, 'end_date' => $end]);
    }

    public static function updateFiscalYear(int $id, Body $b): void
    {
        $name = $b->requiredStr('name');
        $b->requiredStr('start_date');
        $b->requiredStr('end_date');
        self::fiscalYear($id);
        [$start, $end] = self::dates($b);
        DB::table('fiscal_years')->where('id', $id)->update(['name' => $name, 'start_date' => $start, 'end_date' => $end]);
    }

    public static function periods(): array
    {
        return DB::table('accounting_periods')->orderBy('start_date')->orderBy('id')->get()->map(self::periodJson(...))->all();
    }

    public static function period(int $id): object
    {
        return Master::find('accounting_periods', 'id', $id, 'accounting period');
    }

    private static function periodRequired(Body $b): void
    {
        $b->requiredId('fiscal_year_id');
        $b->requiredStr('name');
        $b->requiredStr('start_date');
        $b->requiredStr('end_date');
    }

    public static function createPeriod(Body $b): int
    {
        self::periodRequired($b);
        [$start, $end] = self::dates($b);
        $year = DB::table('fiscal_years')->where('id', $b->int('fiscal_year_id'))->first()
            ?? throw new ApiError(422, 'unknown fiscal_year_id');
        if ($year->status !== 'open') {
            throw new ApiError(422, 'the fiscal year is closed');
        }

        return DB::table('accounting_periods')->insertGetId([
            'fiscal_year_id' => $year->id, 'name' => $b->str('name'), 'start_date' => $start, 'end_date' => $end,
        ]);
    }

    public static function updatePeriod(int $id, Body $b): void
    {
        self::periodRequired($b);
        self::period($id);
        [$start, $end] = self::dates($b);
        $status = $b->text('status') ?? 'open';
        if (! in_array($status, ['open', 'closed'], true)) {
            throw new ApiError(422, 'status must be open or closed');
        }
        DB::table('accounting_periods')->where('id', $id)->update([
            'fiscal_year_id' => $b->int('fiscal_year_id'), 'name' => $b->str('name'),
            'start_date' => $start, 'end_date' => $end, 'status' => $status,
        ]);
    }

    /** The month after the latest period, for the new-period form (domain §13 A1). */
    public static function nextPeriodProposal(): ?array
    {
        $last = DB::table('accounting_periods')->orderByDesc('end_date')->first();
        if ($last === null) {
            return null;
        }
        $start = Dates::addDays($last->end_date, 1);
        $end = Dates::monthEnd($start);
        $year = DB::table('fiscal_years')->where('start_date', '<=', $start)->where('end_date', '>=', $start)->first();
        if ($year !== null) {
            $end = min($end, $year->end_date);
        }

        return ['fiscal_year_id' => $year?->id, 'name' => substr($start, 0, 7), 'start_date' => $start, 'end_date' => $end];
    }

    /** The open period covering a date, creating a monthly one if needed (domain §9.2). */
    public static function periodForPosting(string $date): object
    {
        $covering = DB::table('accounting_periods')->where('start_date', '<=', $date)->where('end_date', '>=', $date)->first();
        if ($covering !== null) {
            if ($covering->status !== 'open') {
                throw new ApiError(422, "the accounting period covering $date is closed");
            }

            return $covering;
        }
        $year = DB::table('fiscal_years')->where('start_date', '<=', $date)->where('end_date', '>=', $date)
            ->where('status', 'open')->first()
            ?? throw new ApiError(422, "no open accounting period or fiscal year covers $date");
        $fields = [
            'fiscal_year_id' => $year->id,
            'name' => substr($date, 0, 7),
            'start_date' => max(Dates::monthStart($date), $year->start_date),
            'end_date' => min(Dates::monthEnd($date), $year->end_date),
        ];
        try {
            $id = DB::transaction(fn () => DB::table('accounting_periods')->insertGetId($fields));
        } catch (QueryException) {
            throw new ApiError(422, "cannot create a period for $date: it would overlap an existing period");
        }

        return self::period($id);
    }
}
