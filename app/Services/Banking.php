<?php

namespace App\Services;

use App\Errors\ApiError;
use App\Http\Body;
use App\Support\Dates;
use App\Support\Dec;
use Illuminate\Support\Facades\DB;

/**
 * Bank statements and reconciliation (spec/api.md §5.13, domain §8).
 *
 * A statement belongs to one postable, active cash account. Each of its lines
 * matches at most one posted journal line on that account with the same
 * signed transaction-currency amount, and a journal line backs at most one
 * statement line anywhere. The schema enforces the account and match rules
 * and freezes reconciled statements; the checks here give the refusals
 * their statuses.
 */
final class Banking
{
    private const CANDIDATES = "
        SELECT jl.id AS journal_line_id, je.id AS journal_entry_id, je.entry_date, je.reference,
               COALESCE(jl.memo, je.memo) AS memo, jl.debit - jl.credit AS amount
        FROM journal_lines jl
        JOIN journal_entries je ON je.id = jl.journal_entry_id
        WHERE je.status = 'posted' AND jl.account_id = ?
          AND NOT EXISTS (SELECT 1 FROM bank_statement_lines b WHERE b.journal_line_id = jl.id)";

    private static function query()
    {
        return DB::table('bank_statements as s')->join('accounts as a', 'a.id', '=', 's.account_id')
            ->select('s.*', 'a.code as account_code', 'a.name as account_name')
            ->selectSub(DB::table('bank_statement_lines')->selectRaw('count(*)')->whereColumn('statement_id', 's.id'), 'n_lines')
            ->selectSub(DB::table('bank_statement_lines')->selectRaw('count(journal_line_id)')->whereColumn('statement_id', 's.id'), 'n_matched')
            ->selectSub(DB::table('bank_statement_lines')->selectRaw('COALESCE(sum(amount), 0)')->whereColumn('statement_id', 's.id'), 'lines_total');
    }

    public static function json(object $s): array
    {
        return [
            'id' => $s->id, 'account_id' => $s->account_id, 'account_code' => $s->account_code, 'account_name' => $s->account_name,
            'statement_date' => $s->statement_date, 'opening_balance' => Dec::fmt4($s->opening_balance),
            'closing_balance' => Dec::fmt4($s->closing_balance), 'reference' => $s->reference, 'status' => $s->status,
            'line_count' => $s->n_lines, 'matched_count' => $s->n_matched, 'lines_total' => Dec::fmt4($s->lines_total),
            'difference' => Dec::fmt4(Dec::of($s->opening_balance)->plus($s->lines_total)->minus($s->closing_balance)),
        ];
    }

    public static function all(): array
    {
        return self::query()->orderByDesc('s.statement_date')->orderByDesc('s.id')->get()->map(self::json(...))->all();
    }

    public static function get(int $id): object
    {
        return self::query()->where('s.id', $id)->first() ?? throw new ApiError(404, 'bank statement not found');
    }

    private static function required(Body $b): void
    {
        $b->requiredId('account_id');
        $b->requiredStr('statement_date');
        $b->requiredStr('opening_balance');
        $b->requiredStr('closing_balance');
    }

    private static function fields(Body $b): array
    {
        $account = $b->int('account_id');
        if (! Master::isPostable($account, ['is_cash' => true])) {
            throw new ApiError(422, 'the account must be a postable, active cash account');
        }

        return [
            'account_id' => $account,
            'statement_date' => Dates::parse($b->str('statement_date'), 'statement_date'),
            'opening_balance' => $b->decimal('opening_balance')->toString(),
            'closing_balance' => $b->decimal('closing_balance')->toString(),
            'reference' => $b->text('reference'),
        ];
    }

    public static function create(Body $b, ?int $userId): int
    {
        self::required($b);

        return DB::table('bank_statements')->insertGetId(self::fields($b) + ['created_by' => $userId]);
    }

    private static function lockOpen(int $id): object
    {
        $s = DB::table('bank_statements')->where('id', $id)->lockForUpdate()->first()
            ?? throw new ApiError(404, 'bank statement not found');
        if ($s->status !== 'open') {
            throw new ApiError(409, 'the bank statement is reconciled');
        }

        return $s;
    }

    public static function update(int $id, Body $b): void
    {
        self::required($b);
        $s = self::lockOpen($id);
        $fields = self::fields($b);
        if ($fields['account_id'] !== $s->account_id
            && DB::table('bank_statement_lines')->where('statement_id', $id)->whereNotNull('journal_line_id')->exists()) {
            throw new ApiError(422, "unmatch the statement's lines before changing its account");
        }
        DB::table('bank_statements')->where('id', $id)->update($fields);
    }

    public static function delete(int $id): void
    {
        self::lockOpen($id);
        DB::table('bank_statement_lines')->where('statement_id', $id)->delete();
        DB::table('bank_statements')->where('id', $id)->delete();
    }

    // ------------------------------------------------------------------
    // Lines
    // ------------------------------------------------------------------

    public static function lineJson(object $l): array
    {
        return [
            'id' => $l->id, 'line_no' => $l->line_no, 'txn_date' => $l->txn_date, 'description' => $l->description,
            'reference' => $l->reference, 'amount' => Dec::fmt4($l->amount), 'journal_line_id' => $l->journal_line_id,
            'journal_entry_id' => $l->journal_entry_id, 'entry_date' => $l->entry_date, 'entry_memo' => $l->entry_memo,
        ];
    }

    public static function lines(int $id): array
    {
        self::get($id);

        return DB::table('bank_statement_lines as l')
            ->leftJoin('journal_lines as jl', 'jl.id', '=', 'l.journal_line_id')
            ->leftJoin('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('l.statement_id', $id)->orderBy('l.line_no')
            ->get(['l.*', 'je.id as journal_entry_id', 'je.entry_date', 'je.memo as entry_memo'])
            ->map(self::lineJson(...))->all();
    }

    private static function append(int $statementId, string $date, string $description, ?string $reference, string $amount): int
    {
        $last = DB::table('bank_statement_lines')->where('statement_id', $statementId)->max('line_no') ?? 0;

        return DB::table('bank_statement_lines')->insertGetId([
            'statement_id' => $statementId, 'line_no' => $last + 1, 'txn_date' => $date,
            'description' => $description, 'reference' => $reference, 'amount' => $amount,
        ]);
    }

    public static function addLine(int $id, Body $b): int
    {
        $b->requiredStr('txn_date');
        $b->requiredStr('description');
        $b->requiredStr('amount');
        self::lockOpen($id);
        $amount = $b->decimal('amount');
        if ($amount->isZero()) {
            throw new ApiError(422, 'amount must not be zero');
        }

        return self::append($id, Dates::parse($b->str('txn_date'), 'txn_date'), $b->str('description'),
            $b->text('reference'), $amount->toString());
    }

    /**
     * Split CSV text into records of fields (RFC 4180, with whitespace allowed
     * before a quoted field); malformed quoting is a 422.
     *
     * @return list<list<string>>
     */
    private static function csvRecords(string $text): array
    {
        $records = [];
        $record = [];
        $field = '';
        $n = strlen($text);
        $i = 0;
        $quoted = false; // the current field was quoted and its closing quote seen
        while ($i < $n) {
            $c = $text[$i];
            if ($c === '"' && trim($field) === '' && ! $quoted) {
                $field = '';
                $i++;
                while (true) {
                    if ($i >= $n) {
                        throw new ApiError(422, 'invalid CSV: unterminated quoted field');
                    }
                    if ($text[$i] === '"') {
                        if (($text[$i + 1] ?? '') === '"') {
                            $field .= '"';
                            $i += 2;

                            continue;
                        }
                        $i++;
                        break;
                    }
                    $field .= $text[$i++];
                }
                $quoted = true;

                continue;
            }
            if ($c === ',') {
                $record[] = $field;
                $field = '';
                $quoted = false;
            } elseif ($c === "\n" || $c === "\r") {
                if ($c === "\r" && ($text[$i + 1] ?? '') === "\n") {
                    $i++;
                }
                $record[] = $field;
                $records[] = $record;
                $record = [];
                $field = '';
                $quoted = false;
            } elseif ($quoted && trim($c) !== '') {
                throw new ApiError(422, 'invalid CSV: text after a closing quote');
            } else {
                $field .= $c;
            }
            $i++;
        }
        if ($field !== '' || $record || $quoted) {
            $record[] = $field;
            $records[] = $record;
        }

        return $records;
    }

    /** Statement lines from `date,description,amount[,reference]` CSV (domain §8.2). */
    public static function parseCsv(string $text): array
    {
        $out = [];
        foreach (self::csvRecords($text) as $i => $rec) {
            $n = $i + 1;
            if (count($rec) === 1 && trim($rec[0]) === '') {
                continue;
            }
            if (count($rec) !== 3 && count($rec) !== 4) {
                throw new ApiError(422, "record $n has ".count($rec).' fields; want date,description,amount[,reference]');
            }
            $rec = array_map('trim', $rec);
            try {
                $date = Dates::parse($rec[0]);
            } catch (ApiError) {
                if ($n === 1) {
                    continue; // a header row
                }
                throw new ApiError(422, "record $n: \"{$rec[0]}\" is not a YYYY-MM-DD date");
            }
            if ($rec[1] === '') {
                throw new ApiError(422, "record $n: the description is empty");
            }
            if (! preg_match('/^-?(\d+(\.\d*)?|\.\d+)$/', $rec[2])) {
                throw new ApiError(422, "record $n: \"{$rec[2]}\" is not a decimal amount");
            }
            $amount = Dec::parse($rec[2], Dec::MONEY, "record $n amount");
            if ($amount->isZero()) {
                throw new ApiError(422, "record $n: the amount must not be zero");
            }
            $out[] = [$date, $rec[1], count($rec) === 4 && $rec[3] !== '' ? $rec[3] : null, $amount->toString()];
        }
        if (! $out) {
            throw new ApiError(422, 'the CSV has no data rows');
        }

        return $out;
    }

    public static function importCsv(int $id, Body $b): int
    {
        $text = $b->str('csv');
        if ($text === null || $text === '') {
            throw new ApiError(400, 'csv is required');
        }
        self::lockOpen($id);
        $rows = self::parseCsv($text);
        foreach ($rows as [$date, $description, $reference, $amount]) {
            self::append($id, $date, $description, $reference, $amount);
        }

        return count($rows);
    }

    private static function lockLine(int $lineId): object
    {
        $line = DB::table('bank_statement_lines as l')->join('bank_statements as s', 's.id', '=', 'l.statement_id')
            ->where('l.id', $lineId)->lockForUpdate()->first(['l.*', 's.status', 's.account_id'])
            ?? throw new ApiError(404, 'bank statement line not found');
        if ($line->status !== 'open') {
            throw new ApiError(409, 'the bank statement is reconciled');
        }

        return $line;
    }

    public static function deleteLine(int $lineId): void
    {
        self::lockLine($lineId);
        DB::table('bank_statement_lines')->where('id', $lineId)->delete();
    }

    public static function match(int $lineId, Body $b): void
    {
        $journalLineId = $b->int('journal_line_id');
        if ($journalLineId === null || $journalLineId <= 0) {
            throw new ApiError(400, 'journal_line_id is required');
        }
        $line = self::lockLine($lineId);
        if ($line->journal_line_id !== null) {
            throw new ApiError(409, 'the statement line is already matched');
        }
        $jl = DB::table('journal_lines as jl')->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('jl.id', $journalLineId)->first(['jl.*', 'je.status']);
        if ($jl === null || $jl->status !== 'posted') {
            throw new ApiError(422, 'journal_line_id must name a line of a posted journal entry');
        }
        if ($jl->account_id !== $line->account_id) {
            throw new ApiError(422, 'the journal line is on a different account');
        }
        if (Dec::of($jl->debit)->minus($jl->credit)->compareTo($line->amount) !== 0) {
            throw new ApiError(422, "the journal line's amount differs from the statement line's");
        }
        if (DB::table('bank_statement_lines')->where('journal_line_id', $journalLineId)->exists()) {
            throw new ApiError(409, 'the journal line already backs another statement line');
        }
        DB::table('bank_statement_lines')->where('id', $lineId)->update(['journal_line_id' => $journalLineId]);
    }

    public static function unmatch(int $lineId): void
    {
        self::lockLine($lineId);
        DB::table('bank_statement_lines')->where('id', $lineId)->update(['journal_line_id' => null]);
    }

    public static function candidates(int $id): array
    {
        $s = self::get($id);

        return array_map(fn ($r) => [
            'journal_line_id' => $r->journal_line_id, 'journal_entry_id' => $r->journal_entry_id,
            'entry_date' => $r->entry_date, 'reference' => $r->reference, 'memo' => $r->memo,
            'amount' => Dec::fmt4($r->amount),
        ], DB::select(self::CANDIDATES.' ORDER BY je.entry_date, je.id, jl.line_no', [$s->account_id]));
    }

    public static function autoMatch(int $id): int
    {
        $s = self::lockOpen($id);
        $matched = 0;
        $lines = DB::table('bank_statement_lines')->where('statement_id', $id)->whereNull('journal_line_id')->orderBy('line_no')->get();
        foreach ($lines as $line) {
            $row = DB::selectOne(
                self::CANDIDATES.' AND jl.debit - jl.credit = CAST(? AS numeric) ORDER BY abs(je.entry_date - CAST(? AS date)), jl.id LIMIT 1',
                [$s->account_id, $line->amount, $line->txn_date],
            );
            if ($row !== null) {
                DB::table('bank_statement_lines')->where('id', $line->id)->update(['journal_line_id' => $row->journal_line_id]);
                $matched++;
            }
        }

        return $matched;
    }

    public static function reconcile(int $id): void
    {
        $s = self::lockOpen($id);
        $lines = DB::table('bank_statement_lines')->where('statement_id', $id);
        if ((clone $lines)->whereNull('journal_line_id')->exists()) {
            throw new ApiError(422, 'every line must be matched before reconciling');
        }
        $total = Dec::of($lines->value(DB::raw('COALESCE(sum(amount), 0)')));
        if (Dec::of($s->opening_balance)->plus($total)->compareTo($s->closing_balance) !== 0) {
            throw new ApiError(422, 'opening balance plus the lines does not equal the closing balance');
        }
        DB::table('bank_statements')->where('id', $id)->update(['status' => 'reconciled', 'reconciled_at' => DB::raw('now()')]);
    }

    public static function reopen(int $id): void
    {
        $s = DB::table('bank_statements')->where('id', $id)->lockForUpdate()->first()
            ?? throw new ApiError(404, 'bank statement not found');
        if ($s->status !== 'reconciled') {
            throw new ApiError(409, 'the bank statement is not reconciled');
        }
        DB::table('bank_statements')->where('id', $id)->update(['status' => 'open', 'reconciled_at' => null]);
    }
}
