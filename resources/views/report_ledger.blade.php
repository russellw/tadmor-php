@extends('layout')
@section('content')
@php($span = $foreign ? 9 : 6)
<div class="pagehead"><h1>{{ $title }}</h1><a href="/reports/trial-balance">Trial balance</a></div>
@include('report_filters')
<div class="tablewrap"><table class="grid">
  <thead><tr><th>Date</th><th>Entry</th><th>Reference</th><th>Memo</th>
    @if ($foreign)<th>Currency</th><th class="num">Debit</th><th class="num">Credit</th>@endif
    <th class="num">{{ $foreign ? 'Base debit' : 'Debit' }}</th><th class="num">{{ $foreign ? 'Base credit' : 'Credit' }}</th><th class="num">Balance</th></tr></thead>
  <tbody>
    <tr><td colspan="{{ $span }}" class="muted">Opening balance</td><td class="num">@amount($opening)</td></tr>
    @foreach ($rows as $r)<tr>
      <td>{{ $r['entry_date'] }}</td><td><a href="/journal-entries/{{ $r['journal_entry_id'] }}">{{ $r['journal_entry_id'] }}</a></td>
      <td>{{ $r['reference'] ?? '' }}</td><td>{{ $r['memo'] ?? '' }}</td>
      @if ($foreign)<td>{{ $r['currency_code'] }}</td><td class="num">@amount($r['debit'])</td><td class="num">@amount($r['credit'])</td>@endif
      <td class="num">@amount($r['base_debit'])</td><td class="num">@amount($r['base_credit'])</td><td class="num">@amount($r['running'])</td>
    </tr>@endforeach
  </tbody>
  <tfoot><tr><td colspan="{{ $span }}">Closing balance ({{ $base }}, debit-positive)</td><td class="num">@amount($closing)</td></tr></tfoot>
</table></div>
@endsection
