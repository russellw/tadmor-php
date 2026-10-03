@extends('layout')
@section('content')
<div class="pagehead"><h1>{{ $title }} <span class="pill s-{{ $m['status'] }}">@label($m['status'])</span></h1></div>
<div class="card"><dl class="facts">
  <dt>Product</dt><dd>{{ $product->sku }} {{ $product->name }}</dd>
  <dt>Warehouse</dt><dd>{{ $warehouse->code }} {{ $warehouse->name }}</dd>
  <dt>Type</dt><dd>@label($m['movement_type'])</dd>
  <dt>Date</dt><dd>{{ $m['movement_date'] }}</dd>
  <dt>Quantity</dt><dd>@qty($m['quantity'])</dd>
  <dt>Unit cost</dt><dd>@amount($m['unit_cost'])</dd>
  <dt>Total cost</dt><dd>@amount($m['total_cost'])</dd>
  <dt>Reference</dt><dd>{{ $m['reference'] ?? '—' }}</dd>
  <dt>Notes</dt><dd>{!! nl2br(e($m['notes'] ?? '—')) !!}</dd>
  <dt>Source</dt><dd>@if ($m['source_type'])Order fulfilment (@label($m['source_type']))@else Entered by hand @endif</dd>
  <dt>Journal entry</dt><dd>@if ($m['journal_entry_id'])<a href="/journal-entries/{{ $m['journal_entry_id'] }}">Entry {{ $m['journal_entry_id'] }}</a>@else—@endif</dd>
</dl></div>
<h2>Actions</h2>
<div class="card">
  <div class="actions">
  @if ($m['status'] === 'draft')
    @if (in_array($m['movement_type'], ['receipt', 'issue']))
    <form method="post" action="/stock-movements/{{ $m['id'] }}/post" class="actions">@token
      @if ($m['movement_type'] === 'receipt')
      <label>Account to credit
        <select class="auto" name="credit_account_id">@foreach ($creditChoices as [$v, $t])<option value="{{ $v }}"@if ((string) $v === (string) $defaultCredit) selected @endif>{{ $t }}</option>@endforeach</select>
      </label>
      @endif
      <button class="primary">Post</button>
    </form>
    @else<span class="muted">Only receipts and issues post to the ledger.</span>@endif
    @if (! $m['source_type'])<a class="button" href="/stock-movements/{{ $m['id'] }}/edit">Edit</a>@endif
    <a class="button" href="/stock-movements/{{ $m['id'] }}/delete">Delete</a>
  @elseif ($user->is_admin)
    <form method="post" action="/stock-movements/{{ $m['id'] }}/unpost">@token<button>Unpost</button></form>
  @endif
  </div>
  @include('errors_list')
</div>
@endsection
