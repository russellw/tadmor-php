@extends('layout')
@section('content')
<div class="pagehead"><h1>Balance sheet</h1></div>
@include('report_filters')
<div class="tablewrap"><table class="grid">
  <thead><tr><th>Code</th><th>Account</th><th class="num">Amount</th></tr></thead>
  <tbody>
    @foreach (['asset' => ['Assets', 'Total assets'], 'liability' => ['Liabilities', 'Total liabilities'], 'equity' => ['Equity', 'Total equity']] as $t => [$heading, $totalLabel])
    <tr class="section"><td colspan="3">{{ $heading }}</td></tr>
    @include('report_rows', ['rows' => $sections[$t]])
    <tr class="subtotal"><td colspan="2">{{ $totalLabel }}</td><td class="num">@amount($totals[$t])</td></tr>
    @endforeach
    <tr><td colspan="2">Current earnings (revenue less expenses not yet closed to equity)</td><td class="num">@amount($earnings)</td></tr>
  </tbody>
  <tfoot><tr><td colspan="2">Liabilities + equity + current earnings</td><td class="num">@amount($liabilitiesAndEquity)</td></tr></tfoot>
</table></div>
<p class="muted">Assets (@amount($totals['asset'])) equal liabilities, equity, and current earnings (@amount($liabilitiesAndEquity)).</p>
@endsection
