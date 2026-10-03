@extends('layout')
@section('content')
<div class="pagehead"><h1>Cash flow</h1></div>
@include('report_filters')
<div class="tablewrap"><table class="grid">
  <thead><tr><th>Code</th><th>Account</th><th class="num">Amount</th></tr></thead>
  <tbody>
  @foreach ($sections as $s)
    <tr class="section"><td colspan="3">{{ ucfirst($s['name']) }} activities</td></tr>
    @if ($s['name'] === 'operating')<tr><td></td><td>Net income</td><td class="num">@amount($cf['net_income'])</td></tr>@endif
    @foreach ($s['rows'] as $r)<tr><td><a href="/accounts/{{ $r['account_id'] }}/ledger">{{ $r['code'] }}</a></td><td>{{ $r['name'] }}</td><td class="num">@amount($r['amount'])</td></tr>@endforeach
    <tr class="subtotal"><td colspan="2">Net cash from {{ $s['name'] }} activities</td><td class="num">@amount($s['subtotal'])</td></tr>
  @endforeach
  </tbody>
  <tfoot>
    <tr><td colspan="2">Opening cash</td><td class="num">@amount($cf['opening_cash'])</td></tr>
    <tr><td colspan="2">Net cash flow</td><td class="num">@amount($cf['net_cash_flow'])</td></tr>
    <tr><td colspan="2">Closing cash</td><td class="num">@amount($cf['closing_cash'])</td></tr>
  </tfoot>
</table></div>
@endsection
