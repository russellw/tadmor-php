@extends('layout')
@section('content')
@php($keys = ['not_yet_due', 'days_1_30', 'days_31_60', 'days_61_90', 'days_over_90', 'total_outstanding'])
<div class="pagehead"><h1>{{ $title }}</h1></div>
<div class="tablewrap"><table class="grid">
  <thead><tr><th>{{ $sales ? 'Customer' : 'Supplier' }}</th><th class="num">Not yet due</th><th class="num">1–30 days</th>
    <th class="num">31–60 days</th><th class="num">61–90 days</th><th class="num">Over 90 days</th><th class="num">Total</th></tr></thead>
  <tbody>@forelse ($rows as $r)<tr>
    <td><a href="/{{ $sales ? 'customers' : 'suppliers' }}/{{ $r['party_id'] }}">{{ $r['party_name'] }}</a></td>
    @foreach ($keys as $k)<td class="num">@amount($r[$k])</td>@endforeach
  </tr>@empty<tr><td colspan="7" class="muted">Nothing outstanding.</td></tr>@endforelse</tbody>
  <tfoot><tr><td>Total</td>@foreach ($keys as $k)<td class="num">@amount($totals[$k])</td>@endforeach</tr></tfoot>
</table></div>
<p class="muted">Posted {{ $sales ? 'invoices' : 'bills' }} with a balance, by due date against today (UTC), in each document's own currency.</p>
@endsection
