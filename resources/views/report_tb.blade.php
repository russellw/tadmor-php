@extends('layout')
@section('content')
<div class="pagehead"><h1>Trial balance</h1></div>
<div class="tablewrap"><table class="grid">
  <thead><tr><th>Code</th><th>Account</th><th>Type</th><th class="num">Debit</th><th class="num">Credit</th><th class="num">Balance</th></tr></thead>
  <tbody>@foreach ($rows as $r)<tr>
    <td><a href="/accounts/{{ $r['account_id'] }}/ledger">{{ $r['code'] }}</a></td><td>{{ $r['name'] }}</td><td>@label($r['account_type'])</td>
    <td class="num">@amount($r['total_debit'])</td><td class="num">@amount($r['total_credit'])</td><td class="num">@amount($r['balance'])</td>
  </tr>@endforeach</tbody>
  <tfoot><tr><td colspan="3">Totals</td><td class="num">@amount($totalDebit)</td><td class="num">@amount($totalCredit)</td><td class="num">@amount($totalBalance)</td></tr></tfoot>
</table></div>
<p class="muted">Balances are debit-positive, in the base currency, from posted entries.</p>
@endsection
