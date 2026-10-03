@extends('layout')
@section('content')
@php($cur = $doc['currency_code'])
<div class="pagehead">
  <h1>{{ $kind->label }} {{ $number }} <span class="pill s-{{ $doc['status'] }}">@label($doc['status'])</span>
    <span class="pill s-{{ $settleStatus }}">@label($settleStatus)</span></h1>
  <a class="button" href="/api/{{ $c }}/{{ $doc['id'] }}/pdf" target="_blank" rel="noopener">PDF</a>
</div>

<div class="columns">
  <div class="card">
    <dl class="facts">
      <dt>{{ $kind->sales ? 'Customer' : 'Supplier' }}</dt><dd><a href="{{ $partyUrl }}">{{ $party }}</a></dd>
      <dt>Date</dt><dd>{{ $date }}</dd>
      @if ($kind->hasDueDate)<dt>Due date</dt><dd>{{ $doc['due_date'] ?? '—' }}</dd>@endif
      <dt>Currency</dt><dd>{{ $cur }}</dd>
      <dt>Reference</dt><dd>{{ $doc['reference'] ?? '—' }}</dd>
      <dt>Memo</dt><dd>{!! nl2br(e($doc['memo'] ?? '—')) !!}</dd>
      <dt>Journal entry</dt><dd>@if ($doc['journal_entry_id'])<a href="/journal-entries/{{ $doc['journal_entry_id'] }}">Entry {{ $doc['journal_entry_id'] }}</a>@else—@endif</dd>
    </dl>
  </div>
  <div class="card">
    <table class="totals full">
      <tr><td>Subtotal</td><td class="num">{{ $cur }} @amount($subtotal)</td></tr>
      <tr><td>Tax</td><td class="num">{{ $cur }} @amount($taxTotal)</td></tr>
      <tr><td><strong>Total</strong></td><td class="num"><strong>{{ $cur }} @amount($doc['total'])</strong></td></tr>
      <tr><td>{{ $kind->credit ? 'Applied' : 'Paid or credited' }}</td><td class="num">{{ $cur }} @amount($doc['amount_applied'])</td></tr>
      <tr><td><strong>{{ $kind->credit ? 'Unapplied' : 'Balance' }}</strong></td><td class="num"><strong>{{ $cur }} @amount($doc['balance'])</strong></td></tr>
    </table>
  </div>
</div>

<h2>Actions</h2>
<div class="card">
  <div class="actions">
    @if ($doc['status'] === 'draft')
      <form method="post" action="/{{ $c }}/{{ $doc['id'] }}/post">@token<button class="primary">Post</button></form>
      @if (! $orderLinked)<a class="button" href="/{{ $c }}/{{ $doc['id'] }}/edit">Edit</a>@else<span class="muted">Produced from an order: delete it to return the quantities, but it cannot be edited.</span>@endif
      <a class="button" href="/{{ $c }}/{{ $doc['id'] }}/delete">Delete</a>
    @elseif ($doc['status'] === 'posted')
      @if ($canApply)
      <form method="post" action="/{{ $c }}/{{ $doc['id'] }}/apply">@token<button class="primary">Apply to open {{ $kind->sales ? 'invoices' : 'bills' }}</button></form>
      @endif
      @if ($user->is_admin)<form method="post" action="/{{ $c }}/{{ $doc['id'] }}/unpost">@token<button>Unpost</button></form>@endif
    @endif
  </div>
  @include('errors_list')
  @include('email_box', ['emailUrl' => "/$c/{$doc['id']}/email"])
</div>

<h2>Lines</h2>
<div class="tablewrap"><table class="grid">
  <thead><tr><th>#</th><th>Description</th><th class="num">Quantity</th><th class="num">{{ $kind->sales ? 'Unit price' : 'Unit cost' }}</th>
    <th>Tax code</th><th class="num">Tax %</th><th class="num">Subtotal</th><th class="num">Tax</th><th class="num">Total</th></tr></thead>
  <tbody>
  @forelse ($lines as $l)<tr>
    <td>{{ $l['line_no'] }}</td><td>{{ $l['description'] }}</td><td class="num">@qty($l['quantity'])</td>
    <td class="num">@amount($l[$priceField])</td><td>{{ $l['tax_code'] ?? '—' }}</td><td class="num">@qty($l['tax_rate'])</td>
    <td class="num">@amount($l['line_subtotal'])</td><td class="num">@amount($l['tax_amount'])</td><td class="num">@amount($l['line_total'])</td>
  </tr>@empty<tr><td colspan="9" class="empty">No lines.</td></tr>@endforelse
  </tbody>
  <tfoot><tr><td colspan="6">Totals ({{ $cur }})</td><td class="num">@amount($subtotal)</td><td class="num">@amount($taxTotal)</td><td class="num">@amount($doc['total'])</td></tr></tfoot>
</table></div>

@if ($kind->credit)
<h2>Applied to</h2>
@if ($appliedTo)
<div class="tablewrap"><table class="grid"><thead><tr><th>{{ $kind->sales ? 'Invoice' : 'Bill' }}</th><th class="num">Amount applied</th></tr></thead>
<tbody>@foreach ($appliedTo as $a)<tr><td><a href="{{ $a['url'] }}">{{ $a['document_number'] }}</a></td><td class="num">{{ $cur }} @amount($a['amount_applied'])</td></tr>@endforeach</tbody></table></div>
@else<p class="empty">Not applied to anything yet.</p>@endif
@else
<h2>Payments and credits applied</h2>
@if ($appliedFrom)
<div class="tablewrap"><table class="grid"><thead><tr><th>Document</th><th>Date</th><th>Status</th><th class="num">Amount applied</th></tr></thead>
<tbody>@foreach ($appliedFrom as $a)<tr><td><a href="{{ $a['url'] }}">{{ $a['label'] }}</a></td><td>{{ $a['date'] }}</td><td><span class="pill s-{{ $a['status'] }}">@label($a['status'])</span></td><td class="num">{{ $cur }} @amount($a['amount_applied'])</td></tr>@endforeach</tbody></table></div>
@else<p class="empty">Nothing applied yet.</p>@endif
@endif
@endsection
