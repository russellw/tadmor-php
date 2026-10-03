@extends('layout')
@section('content')
<div class="pagehead"><h1>Profit and loss</h1></div>
@include('report_filters')
<div class="tablewrap"><table class="grid">
  <thead><tr><th>Code</th><th>Account</th><th class="num">Amount</th></tr></thead>
  <tbody>
    <tr class="section"><td colspan="3">Revenue</td></tr>
    @include('report_rows', ['rows' => $revenue])
    <tr class="subtotal"><td colspan="2">Total revenue</td><td class="num">@amount($totalRevenue)</td></tr>
    <tr class="section"><td colspan="3">Expenses</td></tr>
    @include('report_rows', ['rows' => $expense])
    <tr class="subtotal"><td colspan="2">Total expenses</td><td class="num">@amount($totalExpense)</td></tr>
  </tbody>
  <tfoot><tr><td colspan="2">Net income</td><td class="num">@amount($netIncome)</td></tr></tfoot>
</table></div>
<p class="muted">Base-currency amounts from posted entries; year-end closing entries are excluded.</p>
@endsection
