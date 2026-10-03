<?php

namespace App\Http\Controllers\Api;

use App\Errors\ApiError;
use App\Http\Api;
use App\Http\Body;
use App\Printing\Printing;
use App\Services\DocKind;
use App\Services\Documents;
use App\Services\Kinds;
use App\Services\Orders;
use App\Services\Posting;
use App\Services\Settlement;
use Illuminate\Http\Request;

/**
 * Invoices, bills, credit notes, payments, and orders (spec/api.md §5.9–5.11).
 * The route's `collection` default picks the kind.
 */
class DocumentController
{
    private static function collection(Request $request): string
    {
        return $request->route('collection');
    }

    private static function kind(Request $request): DocKind
    {
        return Kinds::document(self::collection($request));
    }

    // Invoices, bills, credit notes

    public function index(Request $request)
    {
        return Api::ok(Documents::all(self::kind($request)));
    }

    public function store(Request $request)
    {
        return Api::created(['id' => Documents::create(self::kind($request), Body::from($request), Api::user($request)->id)]);
    }

    public function show(Request $request, string $id)
    {
        $kind = self::kind($request);

        return Api::ok(Documents::documentJson($kind, Documents::get($kind, Api::id($id))));
    }

    public function update(Request $request, string $id)
    {
        $id = Api::id($id);
        Documents::update(self::kind($request), $id, Body::from($request));

        return Api::noContent();
    }

    public function destroy(Request $request, string $id)
    {
        Documents::delete(self::kind($request), Api::id($id));

        return Api::noContent();
    }

    public function lines(Request $request, string $id)
    {
        return Api::ok(Documents::documentLines(self::kind($request), Api::id($id)));
    }

    public function post(Request $request, string $id)
    {
        return Api::ok(['journal_entry_id' => Posting::postDocument(self::kind($request), Api::id($id))]);
    }

    public function unpost(Request $request, string $id)
    {
        return Api::ok(['reversal_entry_id' => Posting::unpostDocument(self::kind($request), Api::id($id))]);
    }

    public function applications(Request $request, string $id)
    {
        return Api::ok(Documents::creditNoteApplications(self::kind($request), Api::id($id)));
    }

    /** Apply a payment or credit note. */
    public function apply(Request $request, string $id)
    {
        return Api::ok(['applications' => Settlement::apply(self::collection($request), Api::id($id))]);
    }

    // Payments

    public function paymentIndex(Request $request)
    {
        return Api::ok(Documents::payments(Kinds::payment(self::collection($request))));
    }

    public function paymentStore(Request $request)
    {
        $kind = Kinds::payment(self::collection($request));

        return Api::created(['id' => Documents::createPayment($kind, Body::from($request), Api::user($request)->id)]);
    }

    public function paymentShow(Request $request, string $id)
    {
        $kind = Kinds::payment(self::collection($request));

        return Api::ok(Documents::paymentJson($kind, Documents::payment($kind, Api::id($id))));
    }

    public function paymentUpdate(Request $request, string $id)
    {
        $id = Api::id($id);
        Documents::updatePayment(Kinds::payment(self::collection($request)), $id, Body::from($request));

        return Api::noContent();
    }

    public function paymentDestroy(Request $request, string $id)
    {
        Documents::deletePayment(Kinds::payment(self::collection($request)), Api::id($id));

        return Api::noContent();
    }

    public function paymentApplications(Request $request, string $id)
    {
        return Api::ok(Documents::paymentApplications(Kinds::payment(self::collection($request)), Api::id($id)));
    }

    public function paymentPost(Request $request, string $id)
    {
        return Api::ok(['journal_entry_id' => Posting::postPayment(Kinds::payment(self::collection($request)), Api::id($id))]);
    }

    public function paymentUnpost(Request $request, string $id)
    {
        return Api::ok(['reversal_entry_id' => Posting::unpostPayment(Kinds::payment(self::collection($request)), Api::id($id))]);
    }

    // Orders

    public function orderIndex(Request $request)
    {
        return Api::ok(Orders::all(Kinds::order(self::collection($request))));
    }

    public function orderStore(Request $request)
    {
        $kind = Kinds::order(self::collection($request));

        return Api::created(['id' => Documents::create($kind, Body::from($request), Api::user($request)->id)]);
    }

    public function orderShow(Request $request, string $id)
    {
        $kind = Kinds::order(self::collection($request));

        return Api::ok(Orders::json($kind, Orders::get($kind, Api::id($id))));
    }

    public function orderUpdate(Request $request, string $id)
    {
        $id = Api::id($id);
        Documents::update(Kinds::order(self::collection($request)), $id, Body::from($request));

        return Api::noContent();
    }

    public function orderDestroy(Request $request, string $id)
    {
        Documents::delete(Kinds::order(self::collection($request)), Api::id($id));

        return Api::noContent();
    }

    public function orderLines(Request $request, string $id)
    {
        return Api::ok(Orders::lines(Kinds::order(self::collection($request)), Api::id($id)));
    }

    /** confirm, close, or cancel, named by the route's `action` default. */
    public function orderTransition(Request $request, string $id)
    {
        $action = $request->route('action');
        Orders::$action(Kinds::order(self::collection($request)), Api::id($id));

        return Api::noContent();
    }

    public function orderInvoice(Request $request, string $id)
    {
        $kind = Kinds::order(self::collection($request));
        $id = Api::id($id);
        $new = Orders::invoice($kind, $id, Body::from($request), Api::user($request)->id);

        return Api::created([str_replace(' ', '_', $kind->document->noun).'_id' => $new]);
    }

    public function orderMove(Request $request, string $id)
    {
        $kind = Kinds::order(self::collection($request));
        $id = Api::id($id);

        return Api::created(['movement_ids' => Orders::move($kind, $id, Body::from($request), Api::user($request)->id)]);
    }

    // Printing and email

    public function pdf(Request $request, string $id)
    {
        [$data, $name] = Printing::pdfFor(self::collection($request), Api::id($id));

        return response($data, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => "inline; filename=\"$name\""]);
    }

    public function email(Request $request, string $id)
    {
        $id = Api::id($id);
        $to = Body::from($request)->raw('to') ?? [];
        if (! is_array($to) || array_filter($to, fn ($a) => ! is_string($a))) {
            throw new ApiError(400, 'to must be an array of email addresses');
        }
        $to = array_values(array_filter(array_map('trim', $to), fn ($a) => $a !== ''));

        return Api::ok(['status' => 'sent', 'to' => Printing::email(self::collection($request), $id, $to)]);
    }
}
