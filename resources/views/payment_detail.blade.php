@extends('layout')
@section('content')
@php($cur = $p['currency_code'])
<div class="pagehead">
  <h1>{{ $title }} <span class="pill s-{{ $p['status'] }}">@label($p['status'])</span></h1>
</div>
<div class="columns">
  <div class="card"><dl class="facts">
    <dt>{{ $kind->sales ? 'Customer' : 'Supplier' }}</dt><dd><a href="/{{ $kind->partyTable }}/{{ $p[$kind->partyId] }}">{{ $party }}</a></dd>
    <dt>Date</dt><dd>{{ $p['payment_date'] }}</dd>
    <dt>Method</dt><dd>{{ \App\Ui\Fmt::label($p['method']) ?: '—' }}</dd>
    <dt>Reference</dt><dd>{{ $p['reference'] ?? '—' }}</dd>
    <dt>{{ $cashLabel }}</dt><dd>{{ $cashAccount ?? '—' }}</dd>
    <dt>Journal entry</dt><dd>@if ($p['journal_entry_id'])<a href="/journal-entries/{{ $p['journal_entry_id'] }}">Entry {{ $p['journal_entry_id'] }}</a>@else—@endif</dd>
  </dl></div>
  <div class="card"><table class="totals full">
    <tr><td><strong>Amount</strong></td><td class="num"><strong>{{ $cur }} @amount($p['amount'])</strong></td></tr>
    <tr><td>Applied</td><td class="num">{{ $cur }} @amount($p['amount_applied'])</td></tr>
    <tr><td><strong>Unapplied</strong></td><td class="num"><strong>{{ $cur }} @amount($p['unapplied'])</strong></td></tr>
  </table></div>
</div>
<h2>Actions</h2>
<div class="card">
  <div class="actions">
  @if ($p['status'] === 'draft')
    <form method="post" action="/{{ $c }}/{{ $p['id'] }}/post">@token<button class="primary">Post</button></form>
    <a class="button" href="/{{ $c }}/{{ $p['id'] }}/edit">Edit</a>
    <a class="button" href="/{{ $c }}/{{ $p['id'] }}/delete">Delete</a>
  @elseif ($p['status'] === 'posted')
    @if (\App\Support\Dec::of($p['unapplied'])->isPositive())<form method="post" action="/{{ $c }}/{{ $p['id'] }}/apply">@token<button class="primary">Apply to open {{ $kind->sales ? 'invoices' : 'bills' }}</button></form>@endif
    @if ($user->is_admin)<form method="post" action="/{{ $c }}/{{ $p['id'] }}/unpost">@token<button>Unpost</button></form>@endif
  @endif
  </div>
  @include('errors_list')
</div>
<h2>Applied to</h2>
@if ($applications)
<div class="tablewrap"><table class="grid"><thead><tr><th>{{ $docLabel }}</th><th class="num">Amount applied</th></tr></thead>
<tbody>@foreach ($applications as $a)<tr><td><a href="{{ $a['url'] }}">{{ $a['document_number'] }}</a></td><td class="num">{{ $cur }} @amount($a['amount_applied'])</td></tr>@endforeach</tbody></table></div>
@else<p class="empty">Not applied to anything yet.</p>@endif
@endsection
