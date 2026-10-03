@extends('layout')
@section('content')
@php($open = $s['status'] === 'open')
@php($candidateText = fn ($c) => "{$c['entry_date']} · entry {$c['journal_entry_id']} · ".($c['memo'] ?? $c['reference'] ?? '').' · '.\App\Ui\Fmt::amount($c['amount']))
<div class="pagehead">
  <h1>{{ $title }} <span class="pill s-{{ $s['status'] }}">@label($s['status'])</span></h1>
  <div class="actions">
  @if ($open)
    <a class="button" href="/bank-statements/{{ $s['id'] }}/edit">Edit</a>
    <a class="button" href="/bank-statements/{{ $s['id'] }}/delete">Delete</a>
  @elseif ($user->is_admin)
    <form method="post" action="/bank-statements/{{ $s['id'] }}/reopen">@token<button>Reopen</button></form>
  @endif
  </div>
</div>
<div class="columns">
  <div class="card"><dl class="facts">
    <dt>Account</dt><dd><a href="/accounts/{{ $s['account_id'] }}/ledger">{{ $s['account_code'] }} {{ $s['account_name'] }}</a></dd>
    <dt>Statement date</dt><dd>{{ $s['statement_date'] }}</dd>
    <dt>Reference</dt><dd>{{ $s['reference'] ?? '—' }}</dd>
    <dt>Lines matched</dt><dd>{{ $s['matched_count'] }} of {{ $s['line_count'] }}</dd>
  </dl></div>
  <div class="card"><table class="totals full">
    <tr><td>Opening balance</td><td class="num">@amount($s['opening_balance'])</td></tr>
    <tr><td>Lines</td><td class="num">@amount($s['lines_total'])</td></tr>
    <tr><td>Closing balance</td><td class="num">@amount($s['closing_balance'])</td></tr>
    <tr><td><strong>Difference</strong></td><td class="num"><strong @class(['neg' => \App\Ui\Fmt::isNegative($s['difference'])])>@amount($s['difference'])</strong></td></tr>
  </table></div>
</div>

@if ($open)
<h2>Actions</h2>
<div class="card">
  <div class="actions">
    <form method="post" action="/bank-statements/{{ $s['id'] }}/auto-match">@token<button>Auto-match</button></form>
    <form method="post" action="/bank-statements/{{ $s['id'] }}/reconcile">@token<button class="primary">Reconcile</button></form>
  </div>
  @foreach (['auto', 'reconcile'] as $k)@isset($errors[$k])<p class="error" role="alert">{{ $errors[$k] }}</p>@endisset @endforeach
</div>
@elseif (isset($errors['reopen']))<p class="error" role="alert">{{ $errors['reopen'] }}</p>@endif

<h2>Lines</h2>
<div class="tablewrap"><table class="grid">
  <thead><tr><th>#</th><th>Date</th><th>Description</th><th>Reference</th><th class="num">Amount</th><th>Match</th>@if ($open)<th></th>@endif</tr></thead>
  <tbody>@forelse ($lines as $l)<tr>
    <td>{{ $l['line_no'] }}</td><td>{{ $l['txn_date'] }}</td><td>{{ $l['description'] }}</td><td>{{ $l['reference'] ?? '' }}</td>
    <td class="num">@amount($l['amount'])</td>
    <td>
      @if ($l['journal_line_id'])
        <a href="/journal-entries/{{ $l['journal_entry_id'] }}">Entry {{ $l['journal_entry_id'] }}</a> {{ $l['entry_date'] }} <span class="muted">{{ $l['entry_memo'] ?? '' }}</span>
        @if ($open)<form method="post" action="/bank-statements/{{ $s['id'] }}/lines/{{ $l['id'] }}/unmatch" class="inline">@token<button class="link">Unmatch</button></form>@endif
      @elseif ($open)
        @if ($l['candidates'])
        <form method="post" action="/bank-statements/{{ $s['id'] }}/lines/{{ $l['id'] }}/match" class="actions">@token
          <select class="auto" name="journal_line_id">@foreach ($l['candidates'] as $c)<option value="{{ $c['journal_line_id'] }}">{{ $candidateText($c) }}</option>@endforeach</select>
          <button>Match</button>
        </form>
        @else<span class="muted">No candidate of this amount.</span>@endif
        @if ($allCandidates)
        <details><summary>All candidates</summary>
          <form method="post" action="/bank-statements/{{ $s['id'] }}/lines/{{ $l['id'] }}/match" class="actions">@token
            <select class="auto" name="journal_line_id">@foreach ($allCandidates as $c)<option value="{{ $c['journal_line_id'] }}">{{ $candidateText($c) }}</option>@endforeach</select>
            <button>Match</button>
          </form>
        </details>
        @endif
      @else<span class="muted">Unmatched</span>@endif
      @if ($l['error'])<p class="error" role="alert">{{ $l['error'] }}</p>@endif
    </td>
    @if ($open)<td><form method="post" action="/bank-statements/{{ $s['id'] }}/lines/{{ $l['id'] }}/delete" class="inline">@token<button class="link" title="Delete line">Delete</button></form></td>@endif
  </tr>@empty<tr><td colspan="7" class="muted">No lines yet.</td></tr>@endforelse</tbody>
</table></div>

@if ($open)
<div class="columns mt">
  <form method="post" action="/bank-statements/{{ $s['id'] }}/lines" class="card form">
    @token
    <strong>Add a line</strong>
    <label class="field"><span>Date *</span><input type="date" name="txn_date" value="{{ $values['txn_date'] ?? '' }}" required></label>
    <label class="field"><span>Description *</span><input name="description" value="{{ $values['description'] ?? '' }}" required></label>
    <label class="field"><span>Reference</span><input name="reference" value="{{ $values['reference'] ?? '' }}"></label>
    <label class="field"><span>Amount * (deposits positive)</span><input name="amount" class="num" inputmode="decimal" value="{{ $values['amount'] ?? '' }}" required></label>
    @isset($errors['add'])<p class="error" role="alert">{{ $errors['add'] }}</p>@endisset
    <div class="actions"><button>Add line</button></div>
  </form>
  <form method="post" action="/bank-statements/{{ $s['id'] }}/import" class="card form">
    @token
    <strong>Import CSV</strong>
    <label class="field"><span>Paste <code>date,description,amount[,reference]</code> rows; a header row is skipped.</span>
      <textarea name="csv" rows="7">{{ $values['csv'] ?? '' }}</textarea></label>
    @isset($errors['import'])<p class="error" role="alert">{{ $errors['import'] }}</p>@endisset
    <div class="actions"><button>Import</button></div>
  </form>
</div>
@endif
@endsection
