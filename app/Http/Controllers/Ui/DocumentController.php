<?php

namespace App\Http\Controllers\Ui;

use App\Http\Body;
use App\Printing\Printing;
use App\Services\DocKind;
use App\Services\Documents;
use App\Services\Kinds;
use App\Services\Master;
use App\Services\OrderKind;
use App\Services\Orders;
use App\Services\PaymentKind;
use App\Services\Posting;
use App\Services\Settlement;
use App\Support\Dates;
use App\Support\Dec;
use App\Ui\Choices as Ch;
use App\Ui\Column;
use App\Ui\Field;
use App\Ui\Form;
use App\Ui\Ui;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Invoices, bills, credit notes, payments, and orders (domain §13.4–13.6).
 *
 * One set of actions serves the four line-item documents and, with small
 * differences, the two kinds of order: a list, a header-and-lines form, a
 * detail screen, and the actions each state allows. Payments get the
 * generic form and their own detail screen. The route's `collection`
 * default picks the kind.
 */
class DocumentController
{
    private const PLURAL = ['sales-invoices' => 'Invoices', 'purchase-bills' => 'Bills',
        'sales-credit-notes' => 'Credit notes', 'purchase-credit-notes' => 'Supplier credits'];

    private const SINGULAR = ['sales-invoices' => 'invoice', 'purchase-bills' => 'bill',
        'sales-credit-notes' => 'credit note', 'purchase-credit-notes' => 'supplier credit'];

    private static function c(Request $request): string
    {
        return $request->route('collection');
    }

    private static function id(string $id): int
    {
        if (! ctype_digit($id) || strlen($id) > 9) {
            abort(404);
        }

        return (int) $id;
    }

    private static function partyNames(bool $sales): \Illuminate\Support\Collection
    {
        $table = $sales ? 'customers' : 'suppliers';

        return DB::table("$table as p")->join('organizations as o', 'o.id', '=', 'p.organization_id')->pluck('o.name', 'p.id');
    }

    private static function partyName(bool $sales, int $partyId): string
    {
        return self::partyNames($sales)[$partyId] ?? '';
    }

    /** Run an action posted from a detail screen; on refusal, show the screen with the message beside it. */
    private static function act(Request $request, string $back, string $name, \Closure $fn, \Closure $detail, bool $admin = false)
    {
        if (! $request->isMethod('post')) {
            return redirect($back);
        }
        if ($admin && ! Ui::user($request)->is_admin) {
            return $detail([$name => 'Only administrators can do this.']);
        }
        [, $error] = Ui::attempt($fn);

        return $error ? $detail([$name => $error]) : redirect($back);
    }

    private static function confirmDelete(Request $request, string $title, string $question, string $back, string $done, \Closure $fn)
    {
        $error = null;
        if ($request->isMethod('post')) {
            [, $error] = Ui::attempt($fn);
            if ($error === null) {
                return redirect($done);
            }
        }

        return view('confirm', compact('title', 'question', 'back', 'error'));
    }

    /** Email recipients typed into a box, separated by commas or semicolons. */
    private static function recipients(Request $request): array
    {
        return array_values(array_filter(array_map('trim', explode(',', str_replace(';', ',', (string) $request->input('to', ''))))));
    }

    // ------------------------------------------------------------------
    // The header-and-lines form (D2, O2)
    // ------------------------------------------------------------------

    private static function headerFields(DocKind|OrderKind $kind): array
    {
        $sales = $kind->sales;
        $fs = [
            new Field($kind->partyId, $sales ? 'Customer' : 'Supplier', 'ref', Ch::parties($sales), required: true),
            new Field($kind->number, 'Number', required: true),
            new Field($kind->date, 'Date', 'date', required: true),
        ];
        $second = $kind->secondDate();
        if ($second !== null) {
            $fs[] = new Field($second, $second === 'due_date' ? 'Due date' : 'Expected '.($sales ? 'ship' : 'receipt').' date', 'date');
        }

        return [...$fs,
            new Field('currency_code', 'Currency', 'select', Ch::currencies(), required: true),
            new Field('reference', 'Reference'),
            new Field('memo', 'Memo', 'textarea'),
        ];
    }

    /** The line-item table of a posted form, as API line objects. Blank rows are dropped. */
    private static function postedLines(Request $request, DocKind|OrderKind $kind): array
    {
        $lk = $kind->lines;
        $col = fn ($k) => (array) $request->input("line_$k", []);
        $cols = array_combine(['product_id', 'description', 'quantity', 'price', 'account', 'tax_code', 'tax_rate'],
            array_map($col, ['product_id', 'description', 'quantity', 'price', 'account', 'tax_code', 'tax_rate']));
        $out = [];
        foreach (array_keys($cols['description']) as $i) {
            $get = fn ($k) => trim((string) ($cols[$k][$i] ?? ''));
            if ($get('product_id') === '' && $get('description') === '' && $get('price') === '') {
                continue;
            }
            $id = fn ($k) => ctype_digit($get($k)) && strlen($get($k)) <= 9 ? (int) $get($k) : null;
            $out[] = (object) [
                'product_id' => $id('product_id'),
                'description' => $get('description'),
                'quantity' => $get('quantity') ?: null,
                $lk->price => $get('price') ?: null,
                $lk->account => $id('account'),
                'tax_code' => $get('tax_code') ?: null,
                'tax_rate' => $get('tax_rate') ?: null,
            ];
        }

        return $out;
    }

    /** Pickers offer active records, plus any inactive one a line already uses. */
    private static function keep(array $choices, array $lines, string $key): array
    {
        $have = array_map(fn ($c) => (string) $c[0], $choices);
        foreach ($lines as $l) {
            $v = $l[$key] ?? null;
            if ($v !== null && $v !== '' && ! in_array((string) $v, $have, true)) {
                $choices[] = [$v, "$v (inactive)"];
                $have[] = (string) $v;
            }
        }

        return $choices;
    }

    private static function documentForm(Request $request, DocKind|OrderKind $kind, string $title, array $initial, array $initialLines,
        \Closure $save, string $back)
    {
        $error = null;
        $lk = $kind->lines;
        $values = $initial;
        $lines = array_map(fn ($l) => ['product_id' => $l['product_id'], 'description' => $l['description'],
            'quantity' => Dec::fmt4($l['quantity']), 'price' => $l[$lk->price], 'account' => $l[$lk->account],
            'tax_code' => $l['tax_code'], 'tax_rate' => $l['tax_rate']], $initialLines);
        if ($request->isMethod('post')) {
            $fields = self::headerFields($kind);
            $data = [];
            foreach ($fields as $f) {
                $data[$f->name] = $f->toJson($request->input($f->name));
            }
            $posted = self::postedLines($request, $kind);
            $data['lines'] = $posted;
            [$result, $error] = Ui::attempt(fn () => $save(Body::of((object) $data)));
            if ($error === null) {
                return redirect("/{$kind->collection}/$result");
            }
            $values = [];
            foreach ($fields as $f) {
                $values[$f->name] = (string) $request->input($f->name, '');
            }
            $lines = array_map(fn ($l) => ['product_id' => $l->product_id, 'description' => $l->description, 'quantity' => $l->quantity,
                'price' => $l->{$lk->price}, 'account' => $l->{$lk->account}, 'tax_code' => $l->tax_code, 'tax_rate' => $l->tax_rate], $posted);
        }
        if (! $lines) {
            $lines = [[]];
        }
        $sales = $kind->sales;
        $products = [];
        foreach (DB::table('products')->where('is_active', true)->get() as $p) {
            $products[$p->id] = ['description' => $p->name, 'tax_code' => $p->tax_code,
                'price' => $sales ? Dec::fmt4($p->unit_price) : null, 'account' => $sales ? $p->revenue_account_id : null];
        }

        return view('document_form', [
            'title' => $title, 'back' => $back, 'error' => $error,
            'header' => (new Form(self::headerFields($kind), $values))->rows(),
            'lines' => $lines, 'priceLabel' => $sales ? 'Unit price' : 'Unit cost',
            'accountLabel' => $sales ? 'Revenue account' : 'Expense account',
            'productChoices' => self::keep((Ch::products())(), $lines, 'product_id'),
            'accountChoices' => self::keep((Ch::postableAccounts())(), $lines, 'account'),
            'taxChoices' => self::keep((Ch::taxCodes())(), $lines, 'tax_code'),
            'clientData' => [
                'products' => (object) $products,
                'taxes' => (object) DB::table('tax_codes')->get()->mapWithKeys(fn ($t) => [$t->code => Dec::fmt4($t->rate)])->all(),
                'partyCurrency' => (object) DB::table($kind->partyTable)->where('is_active', true)->pluck('currency_code', 'id')->all(),
            ],
            'isOrder' => $kind instanceof OrderKind,
        ]);
    }

    // ------------------------------------------------------------------
    // Invoices, bills, credit notes (D1–D7)
    // ------------------------------------------------------------------

    public function index(Request $request)
    {
        $c = self::c($request);
        $kind = Kinds::document($c);
        $names = self::partyNames($kind->sales);
        $rows = array_map(fn ($r) => $r + ['party_name' => $names[$r[$kind->partyId]] ?? null], Documents::all($kind));
        $cols = [new Column('Number', $kind->number), new Column($kind->sales ? 'Customer' : 'Supplier', 'party_name'),
            new Column('Date', $kind->date)];
        if ($kind->hasDueDate) {
            $cols[] = new Column('Due', 'due_date');
        }
        $cols = [...$cols, new Column('Currency', 'currency_code'), new Column('Total', 'total', true, 'amount'),
            new Column($kind->credit ? 'Unapplied' : 'Balance', 'balance', true, 'amount'),
            new Column('Status', 'status', kind: 'status'),
            new Column(ucfirst(explode('_', $kind->statusField)[0]), $kind->statusField, kind: 'status')];

        return Ui::listPage(self::PLURAL[$c], $rows, $cols, fn ($r) => "/$c/{$r['id']}", ["/$c/new", 'New '.self::SINGULAR[$c]]);
    }

    public function create(Request $request)
    {
        $c = self::c($request);
        $kind = Kinds::document($c);
        $party = (string) $request->query('party', '');
        $initial = [$kind->date => Dates::today(), 'currency_code' => Master::baseCurrency(),
            $kind->partyId => ctype_digit($party) ? (int) $party : null];

        return self::documentForm($request, $kind, 'New '.self::SINGULAR[$c], $initial, [],
            fn (Body $b) => Documents::create($kind, $b, Ui::user($request)->id), "/$c");
    }

    public function edit(Request $request, string $id)
    {
        $c = self::c($request);
        $kind = Kinds::document($c);
        $id = self::id($id);
        $doc = Documents::documentJson($kind, Documents::get($kind, $id));

        return self::documentForm($request, $kind, 'Edit '.self::SINGULAR[$c]." {$doc[$kind->number]}", $doc,
            Documents::documentLines($kind, $id), function (Body $b) use ($kind, $id) {
                Documents::update($kind, $id, $b);

                return $id;
            }, "/$c/$id");
    }

    private static function detail(Request $request, DocKind $kind, int $id, array $errors = [], ?array $emailResult = null)
    {
        $c = $kind->collection;
        $d = Documents::get($kind, $id);
        $doc = Documents::documentJson($kind, $d);
        $lines = Documents::documentLines($kind, $id);
        $ctx = [
            'title' => "{$kind->label} {$doc[$kind->number]}", 'kind' => $kind, 'c' => $c, 'doc' => $doc,
            'number' => $doc[$kind->number], 'date' => $doc[$kind->date],
            'party' => self::partyName($kind->sales, $doc[$kind->partyId]), 'partyUrl' => "/{$kind->partyTable}/{$doc[$kind->partyId]}",
            'lines' => $lines, 'priceField' => $kind->lines->price, 'subtotal' => $d->subtotal, 'taxTotal' => $d->tax_total,
            'orderLinked' => (bool) array_filter($lines, fn ($l) => $l['order_line_id'] !== null),
            'errors' => $errors, 'emailResult' => $emailResult, 'settleStatus' => $doc[$kind->statusField],
            'canApply' => $kind->credit && $doc['status'] === 'posted' && Dec::of($doc['balance'])->isPositive(),
        ];
        if ($kind->credit) {
            $target = Kinds::settled($kind->sales);
            $ctx['appliedTo'] = array_map(fn ($a) => $a + ['url' => "/{$target->collection}/{$a['document_id']}"],
                Documents::creditNoteApplications($kind, $id));
        } else {
            $ctx['appliedFrom'] = array_map(fn ($a) => $a + ['url' => "/{$a['collection']}/{$a['id']}"], Documents::applicationsTo($kind, $id));
        }

        return view('document_detail', $ctx);
    }

    public function show(Request $request, string $id)
    {
        return self::detail($request, Kinds::document(self::c($request)), self::id($id));
    }

    public function action(Request $request, string $id)
    {
        $kind = Kinds::document(self::c($request));
        $id = self::id($id);
        $action = $request->route('action');
        $fn = match ($action) {
            'post' => fn () => Posting::postDocument($kind, $id),
            'unpost' => fn () => Posting::unpostDocument($kind, $id),
            'apply' => fn () => Settlement::apply($kind->collection, $id),
        };

        return self::act($request, "/{$kind->collection}/$id", $action, $fn,
            fn ($errors) => self::detail($request, $kind, $id, $errors), admin: $action === 'unpost');
    }

    public function delete(Request $request, string $id)
    {
        $c = self::c($request);
        $kind = Kinds::document($c);
        $id = self::id($id);
        $number = Documents::get($kind, $id)->{$kind->number};
        $singular = self::SINGULAR[$c];

        return self::confirmDelete($request, "Delete $singular $number?",
            "This deletes draft $singular $number and its lines. It cannot be undone.", "/$c/$id", "/$c",
            fn () => Documents::delete($kind, $id));
    }

    public function email(Request $request, string $id)
    {
        $c = self::c($request);
        $id = self::id($id);
        $kind = Kinds::$documents[$c] ?? Kinds::order($c);
        $detail = fn ($errors, $sent = null) => $kind instanceof DocKind
            ? self::detail($request, $kind, $id, $errors, $sent)
            : self::orderDetail($request, $kind, $id, $errors, $sent);
        if (! $request->isMethod('post')) {
            return redirect("/$c/$id");
        }
        [$sent, $error] = Ui::attempt(fn () => Printing::email($c, $id, self::recipients($request)));

        return $error ? $detail(['email' => $error]) : $detail([], $sent);
    }

    // ------------------------------------------------------------------
    // Payments (P1–P4)
    // ------------------------------------------------------------------

    private static function paymentFields(PaymentKind $kind): array
    {
        $sales = $kind->sales;

        return [
            new Field($kind->partyId, $sales ? 'Customer' : 'Supplier', 'ref', Ch::parties($sales), required: true),
            new Field('payment_date', 'Date', 'date', required: true),
            new Field('currency_code', 'Currency', 'select', Ch::currencies(), required: true),
            new Field('amount', 'Amount', 'decimal', required: true),
            new Field('method', 'Method', 'select', Ch::paymentMethods()),
            new Field('reference', 'Reference'),
            new Field($kind->cashAccount, $sales ? 'Deposit account' : 'Payment account', 'ref', Ch::postableAccounts(),
                help: 'The cash or bank account; posting needs it.'),
        ];
    }

    private static function paymentSingular(PaymentKind $kind): string
    {
        return $kind->sales ? 'customer payment' : 'supplier payment';
    }

    public function paymentIndex(Request $request)
    {
        $c = self::c($request);
        $kind = Kinds::payment($c);
        $names = self::partyNames($kind->sales);
        $rows = array_map(fn ($r) => $r + ['party_name' => $names[$r[$kind->partyId]] ?? null], Documents::payments($kind));

        return Ui::listPage($kind->sales ? 'Customer payments' : 'Supplier payments', $rows, [
            new Column('Date', 'payment_date'), new Column($kind->sales ? 'Customer' : 'Supplier', 'party_name'),
            new Column('Method', 'method', kind: 'label'), new Column('Currency', 'currency_code'),
            new Column('Amount', 'amount', true, 'amount'), new Column('Applied', 'amount_applied', true, 'amount'),
            new Column('Unapplied', 'unapplied', true, 'amount'), new Column('Status', 'status', kind: 'status'),
        ], fn ($r) => "/$c/{$r['id']}", ["/$c/new", 'New '.self::paymentSingular($kind)]);
    }

    public function paymentCreate(Request $request)
    {
        $c = self::c($request);
        $kind = Kinds::payment($c);
        $party = (string) $request->query('party', '');

        return Ui::crudForm($request, 'New '.self::paymentSingular($kind), self::paymentFields($kind),
            ['payment_date' => Dates::today(), 'currency_code' => Master::baseCurrency(), $kind->partyId => ctype_digit($party) ? (int) $party : null],
            fn (Body $b) => Documents::createPayment($kind, $b, Ui::user($request)->id), fn ($id) => "/$c/$id", "/$c");
    }

    public function paymentEdit(Request $request, string $id)
    {
        $c = self::c($request);
        $kind = Kinds::payment($c);
        $id = self::id($id);
        $p = Documents::paymentJson($kind, Documents::payment($kind, $id));

        return Ui::crudForm($request, 'Edit '.self::paymentSingular($kind), self::paymentFields($kind), $p,
            fn (Body $b) => Documents::updatePayment($kind, $id, $b), fn () => "/$c/$id", "/$c/$id");
    }

    private static function paymentDetail(Request $request, PaymentKind $kind, int $id, array $errors = [])
    {
        $p = Documents::paymentJson($kind, Documents::payment($kind, $id));
        $cash = $p[$kind->cashAccount] ? DB::table('accounts')->where('id', $p[$kind->cashAccount])->first() : null;

        return view('payment_detail', [
            'title' => ucfirst(self::paymentSingular($kind))." $id", 'kind' => $kind, 'c' => $kind->collection, 'p' => $p,
            'party' => self::partyName($kind->sales, $p[$kind->partyId]),
            'cashLabel' => $kind->sales ? 'Deposit account' : 'Payment account',
            'cashAccount' => $cash ? "{$cash->code} {$cash->name}" : null,
            'applications' => array_map(fn ($a) => $a + ['url' => "/{$kind->documents->collection}/{$a['document_id']}"],
                Documents::paymentApplications($kind, $id)),
            'errors' => $errors, 'docLabel' => $kind->sales ? 'Invoice' : 'Bill',
        ]);
    }

    public function paymentShow(Request $request, string $id)
    {
        return self::paymentDetail($request, Kinds::payment(self::c($request)), self::id($id));
    }

    public function paymentAction(Request $request, string $id)
    {
        $kind = Kinds::payment(self::c($request));
        $id = self::id($id);
        $action = $request->route('action');
        $fn = match ($action) {
            'post' => fn () => Posting::postPayment($kind, $id),
            'unpost' => fn () => Posting::unpostPayment($kind, $id),
            'apply' => fn () => Settlement::apply($kind->collection, $id),
        };

        return self::act($request, "/{$kind->collection}/$id", $action, $fn,
            fn ($errors) => self::paymentDetail($request, $kind, $id, $errors), admin: $action === 'unpost');
    }

    public function paymentDelete(Request $request, string $id)
    {
        $c = self::c($request);
        $kind = Kinds::payment($c);
        $id = self::id($id);
        Documents::payment($kind, $id);
        $singular = self::paymentSingular($kind);

        return self::confirmDelete($request, "Delete $singular $id?", "This deletes the draft $singular. It cannot be undone.",
            "/$c/$id", "/$c", fn () => Documents::deletePayment($kind, $id));
    }

    // ------------------------------------------------------------------
    // Orders (O1–O7)
    // ------------------------------------------------------------------

    public function orderIndex(Request $request)
    {
        $c = self::c($request);
        $kind = Kinds::order($c);
        $names = self::partyNames($kind->sales);
        $rows = array_map(fn ($r) => $r + ['party_name' => $names[$r[$kind->partyId]] ?? null], Orders::all($kind));

        return Ui::listPage($kind->sales ? 'Sales orders' : 'Purchase orders', $rows, [
            new Column('Number', 'order_number'), new Column($kind->sales ? 'Customer' : 'Supplier', 'party_name'),
            new Column('Date', 'order_date'), new Column('Currency', 'currency_code'),
            new Column('Total', 'total', true, 'amount'), new Column('Status', 'status', kind: 'status'),
            new Column(ucfirst($kind->billed), "{$kind->billed}_status", kind: 'status'),
            new Column(ucfirst($kind->moved), "{$kind->moved}_status", kind: 'status'),
        ], fn ($r) => "/$c/{$r['id']}", ["/$c/new", "New {$kind->noun}"]);
    }

    public function orderCreate(Request $request)
    {
        $kind = Kinds::order(self::c($request));

        return self::documentForm($request, $kind, "New {$kind->noun}",
            ['order_date' => Dates::today(), 'currency_code' => Master::baseCurrency()], [],
            fn (Body $b) => Documents::create($kind, $b, Ui::user($request)->id), "/{$kind->collection}");
    }

    public function orderEdit(Request $request, string $id)
    {
        $kind = Kinds::order(self::c($request));
        $id = self::id($id);
        $o = Orders::json($kind, Orders::get($kind, $id));

        return self::documentForm($request, $kind, "Edit {$kind->noun} {$o['order_number']}", $o, Orders::lines($kind, $id),
            function (Body $b) use ($kind, $id) {
                Documents::update($kind, $id, $b);

                return $id;
            }, "/{$kind->collection}/$id");
    }

    /** The documents and movements fulfilment has produced from an order. */
    private static function produced(OrderKind $kind, int $id): array
    {
        $doc = $kind->document;
        $lineIds = DB::table($kind->lines->table)->where('order_id', $id)->pluck('id');
        $docIds = DB::table($doc->lines->table)->whereIn('order_line_id', $lineIds)->distinct()->pluck($doc->key);
        $documents = DB::table($doc->table)->whereIn('id', $docIds)->orderBy('id')->get()
            ->map(fn ($d) => [$d->{$doc->number}, $d->status, "/{$doc->collection}/{$d->id}"])->all();
        $movements = DB::table('stock_movements')->where('source_type', $kind->sales ? 'sales_order_line' : 'purchase_order_line')
            ->whereIn('source_id', $lineIds)->orderBy('id')->get()
            ->map(fn ($m) => [$m->id, $m->movement_date, $m->journal_entry_id ? 'posted' : 'draft', "/stock-movements/{$m->id}"])->all();

        return ['documents' => $documents, 'movements' => $movements];
    }

    private static function orderDetail(Request $request, OrderKind $kind, int $id, array $errors = [], ?array $emailResult = null)
    {
        $o = Orders::get($kind, $id);
        $oj = Orders::json($kind, $o);
        $lines = Orders::lines($kind, $id);
        $positive = fn ($q) => (bool) array_filter($lines, fn ($l) => Dec::of($l[$q])->isPositive());

        return view('order_detail', [
            'title' => "{$kind->label} {$oj['order_number']}", 'kind' => $kind, 'c' => $kind->collection, 'o' => $oj, 'lines' => $lines,
            'party' => self::partyName($kind->sales, $oj[$kind->partyId]),
            'priceField' => $kind->lines->price, 'subtotal' => $o->subtotal, 'taxTotal' => $o->tax_total,
            'fulfilled' => Orders::isFulfilled($kind, $id),
            'canBill' => $positive("qty_to_{$kind->billVerb}"), 'canMove' => $positive("qty_to_{$kind->moveVerb}"),
            'produced' => self::produced($kind, $id), 'errors' => $errors, 'emailResult' => $emailResult,
        ]);
    }

    public function orderShow(Request $request, string $id)
    {
        return self::orderDetail($request, Kinds::order(self::c($request)), self::id($id));
    }

    public function orderAction(Request $request, string $id)
    {
        $kind = Kinds::order(self::c($request));
        $id = self::id($id);
        $action = $request->route('action');

        return self::act($request, "/{$kind->collection}/$id", $action, fn () => Orders::$action($kind, $id),
            fn ($errors) => self::orderDetail($request, $kind, $id, $errors));
    }

    public function orderDelete(Request $request, string $id)
    {
        $kind = Kinds::order(self::c($request));
        $id = self::id($id);
        $number = Orders::get($kind, $id)->order_number;

        return self::confirmDelete($request, "Delete {$kind->noun} $number?",
            "This deletes draft {$kind->noun} $number and its lines. It cannot be undone.",
            "/{$kind->collection}/$id", "/{$kind->collection}", fn () => Documents::delete($kind, $id));
    }

    /** Quantities typed per order line, defaulting to what remains. */
    private static function chosen(Request $request, array $lines, string $remaining): array
    {
        $out = [];
        foreach ($lines as $l) {
            $out[$l['order_line_id']] = $request->isMethod('post')
                ? (trim((string) $request->input("qty_{$l['order_line_id']}", '')) ?: '0')
                : $l[$remaining];
        }

        return $out;
    }

    /** O5: invoice (or bill) the order, partially if quantities are lowered. */
    public function orderBill(Request $request, string $id)
    {
        $kind = Kinds::order(self::c($request));
        $id = self::id($id);
        $o = Orders::json($kind, Orders::get($kind, $id));
        $doc = $kind->document;
        $remaining = "qty_to_{$kind->billVerb}";
        $lines = array_values(array_filter(Orders::lines($kind, $id), fn ($l) => Dec::of($l[$remaining])->isPositive()));
        $qtys = self::chosen($request, $lines, $remaining);
        $values = ['number' => '', 'date' => Dates::today(), 'due_date' => ''];
        $error = null;
        if ($request->isMethod('post')) {
            $values = array_map(fn ($k) => trim((string) $request->input($k, '')), array_combine(array_keys($values), array_keys($values)));
            $body = Body::of((object) [
                $doc->number => $values['number'] ?: null, $doc->date => $values['date'] ?: null, 'due_date' => $values['due_date'] ?: null,
                'lines' => array_map(fn ($k, $v) => (object) ['order_line_id' => $k, 'quantity' => $v], array_keys($qtys), $qtys),
            ]);
            [$new, $error] = Ui::attempt(fn () => Orders::invoice($kind, $id, $body, Ui::user($request)->id));
            if ($error === null) {
                return redirect("/{$doc->collection}/$new");
            }
        }

        return view('order_fulfil', [
            'title' => ucfirst($kind->billVerb)." {$kind->noun} {$o['order_number']}", 'error' => $error,
            'back' => "/{$kind->collection}/$id", 'billing' => true, 'values' => $values, 'kind' => $kind,
            'rows' => array_map(fn ($l) => $l + ['chosen' => $qtys[$l['order_line_id']], 'remaining' => $l[$remaining]], $lines),
            'numberLabel' => $kind->sales ? 'Invoice number' : 'Bill number', 'dateLabel' => $kind->sales ? 'Invoice date' : 'Bill date',
        ]);
    }

    /** O6: ship (or receive) the order's stocked lines into draft movements. */
    public function orderMove(Request $request, string $id)
    {
        $kind = Kinds::order(self::c($request));
        $id = self::id($id);
        $o = Orders::json($kind, Orders::get($kind, $id));
        $remaining = "qty_to_{$kind->moveVerb}";
        $lines = array_values(array_filter(Orders::lines($kind, $id), fn ($l) => Dec::of($l[$remaining])->isPositive()));
        $qtys = self::chosen($request, $lines, $remaining);
        $values = ['warehouse_id' => '', 'movement_date' => Dates::today(), 'reference' => $o['order_number']];
        $error = null;
        if ($request->isMethod('post')) {
            $values = array_map(fn ($k) => trim((string) $request->input($k, '')), array_combine(array_keys($values), array_keys($values)));
            $wh = $values['warehouse_id'];
            $body = Body::of((object) [
                'warehouse_id' => ctype_digit($wh) && strlen($wh) <= 9 ? (int) $wh : null,
                'movement_date' => $values['movement_date'] ?: null, 'reference' => $values['reference'] ?: null,
                'lines' => array_map(fn ($k, $v) => (object) ['order_line_id' => $k, 'quantity' => $v], array_keys($qtys), $qtys),
            ]);
            [$created, $error] = Ui::attempt(fn () => Orders::move($kind, $id, $body, Ui::user($request)->id));
            if ($error === null) {
                return view('order_moved', ['title' => ucfirst($kind->moved)." {$kind->noun} {$o['order_number']}",
                    'movements' => $created, 'back' => "/{$kind->collection}/$id"]);
            }
        }

        return view('order_fulfil', [
            'title' => ucfirst($kind->moveVerb)." {$kind->noun} {$o['order_number']}", 'error' => $error,
            'back' => "/{$kind->collection}/$id", 'billing' => false, 'values' => $values, 'kind' => $kind,
            'warehouses' => (Ch::warehouses())(),
            'rows' => array_map(fn ($l) => $l + ['chosen' => $qtys[$l['order_line_id']], 'remaining' => $l[$remaining]], $lines),
        ]);
    }
}
