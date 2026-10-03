@extends('layout')
@section('content')
@php($cur = $o['currency_code'])
@php($billedStatus = $o[$kind->billed.'_status'])
@php($movedStatus = $o[$kind->moved.'_status'])
<div class="pagehead">
  <h1>{{ $kind->label }} {{ $o['order_number'] }} <span class="pill s-{{ $o['status'] }}">@label($o['status'])</span></h1>
  <a class="button" href="/api/{{ $c }}/{{ $o['id'] }}/pdf" target="_blank" rel="noopener">PDF</a>
</div>
<div class="columns">
  <div class="card"><dl class="facts">
    <dt>{{ $kind->sales ? 'Customer' : 'Supplier' }}</dt><dd><a href="/{{ $kind->partyTable }}/{{ $o[$kind->partyId] }}">{{ $party }}</a></dd>
    <dt>Order date</dt><dd>{{ $o['order_date'] }}</dd>
    <dt>Expected {{ $kind->sales ? 'ship' : 'receipt' }}</dt><dd>{{ $o[$kind->expectedDate] ?? '—' }}</dd>
    <dt>Currency</dt><dd>{{ $cur }}</dd>
    <dt>@label($kind->billed)</dt><dd><span class="pill s-{{ $billedStatus }}">@label($billedStatus)</span></dd>
    <dt>@label($kind->moved)</dt><dd><span class="pill s-{{ $movedStatus }}">@label($movedStatus)</span></dd>
    <dt>Reference</dt><dd>{{ $o['reference'] ?? '—' }}</dd>
    <dt>Memo</dt><dd>{!! nl2br(e($o['memo'] ?? '—')) !!}</dd>
  </dl></div>
  <div class="card"><table class="totals full">
    <tr><td>Subtotal</td><td class="num">{{ $cur }} @amount($subtotal)</td></tr>
    <tr><td>Tax</td><td class="num">{{ $cur }} @amount($taxTotal)</td></tr>
    <tr><td><strong>Total</strong></td><td class="num"><strong>{{ $cur }} @amount($o['total'])</strong></td></tr>
  </table></div>
</div>

<h2>Actions</h2>
<div class="card">
  <div class="actions">
  @if ($o['status'] === 'draft')
    <form method="post" action="/{{ $c }}/{{ $o['id'] }}/confirm">@token<button class="primary">Confirm</button></form>
    <a class="button" href="/{{ $c }}/{{ $o['id'] }}/edit">Edit</a>
    <a class="button" href="/{{ $c }}/{{ $o['id'] }}/delete">Delete</a>
    <form method="post" action="/{{ $c }}/{{ $o['id'] }}/cancel">@token<button>Cancel order</button></form>
  @elseif ($o['status'] === 'open')
    @if ($canBill)<a class="button primary" href="/{{ $c }}/{{ $o['id'] }}/{{ $kind->billVerb }}">{{ ucfirst($kind->billVerb) }}</a>@endif
    @if ($canMove)<a class="button primary" href="/{{ $c }}/{{ $o['id'] }}/{{ $kind->moveVerb }}">{{ ucfirst($kind->moveVerb) }}</a>@endif
    <form method="post" action="/{{ $c }}/{{ $o['id'] }}/close">@token<button>Close</button></form>
    @if (! $fulfilled)<form method="post" action="/{{ $c }}/{{ $o['id'] }}/cancel">@token<button>Cancel order</button></form>@endif
  @endif
  </div>
  @include('errors_list')
  @include('email_box', ['emailUrl' => "/$c/{$o['id']}/email"])
</div>

<h2>Lines</h2>
<div class="tablewrap"><table class="grid">
  <thead><tr><th>#</th><th>Description</th><th class="num">Ordered</th><th class="num">{{ $kind->sales ? 'Unit price' : 'Unit cost' }}</th>
    <th class="num">Tax %</th><th class="num">Total</th>
    <th class="num">{{ ucfirst($kind->billed) }}</th><th class="num">To {{ $kind->billVerb }}</th>
    <th class="num">{{ ucfirst($kind->moved) }}</th><th class="num">To {{ $kind->moveVerb }}</th></tr></thead>
  <tbody>@forelse ($lines as $l)<tr>
    <td>{{ $l['line_no'] }}</td><td>{{ $l['description'] }}</td><td class="num">@qty($l['quantity'])</td>
    <td class="num">@amount($l[$priceField])</td><td class="num">@qty($l['tax_rate'])</td><td class="num">@amount($l['line_total'])</td>
    <td class="num">@qty($l['qty_'.$kind->billed])</td><td class="num">@qty($l['qty_to_'.$kind->billVerb])</td>
    <td class="num">@qty($l['qty_'.$kind->moved])</td><td class="num">@qty($l['qty_to_'.$kind->moveVerb])</td>
  </tr>@empty<tr><td colspan="10" class="empty">No lines.</td></tr>@endforelse</tbody>
</table></div>

@if ($produced['documents'] || $produced['movements'])
<h2>Produced from this order</h2>
<ul class="card plainlist">
  @foreach ($produced['documents'] as [$number, $status, $url])<li><a href="{{ $url }}">{{ $kind->sales ? 'Invoice' : 'Bill' }} {{ $number }}</a> <span class="pill s-{{ $status }}">@label($status)</span></li>@endforeach
  @foreach ($produced['movements'] as [$mid, $date, $status, $url])<li><a href="{{ $url }}">Stock movement {{ $mid }}</a> {{ $date }} <span class="pill s-{{ $status }}">@label($status)</span></li>@endforeach
</ul>
@endif
@endsection
