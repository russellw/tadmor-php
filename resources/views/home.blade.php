@extends('layout')
@section('content')
<div class="pagehead"><h1>Home</h1><span class="sub">Today is {{ $today }} (UTC)</span></div>

<div class="actions mb-l">
  <a class="button primary" href="/sales-invoices/new">New invoice</a>
  <a class="button" href="/customer-payments/new">New customer payment</a>
  <a class="button primary" href="/purchase-bills/new">New bill</a>
  <a class="button" href="/supplier-payments/new">New supplier payment</a>
  <a class="button" href="/sales-orders/new">New sales order</a>
  <a class="button" href="/purchase-orders/new">New purchase order</a>
</div>

<div class="columns">
  @foreach ([['Receivables outstanding', $receivables, 'invoice'], ['Payables outstanding', $payables, 'bill']] as [$heading, $rows, $noun])
  <section class="card">
    <h2 class="mt-0">{{ $heading }}</h2>
    @forelse ($rows as $r)
    <p><span class="big">{{ $r->currency_code }} @amount($r->total)</span><br>
      <span class="muted">{{ $r->n }} {{ $noun }}{{ $r->n == 1 ? '' : 's' }}, overdue {{ $r->currency_code }} @amount($r->overdue)</span></p>
    @empty<p class="muted">Nothing outstanding.</p>@endforelse
  </section>
  @endforeach
  <section class="card">
    <h2 class="mt-0">Work in progress</h2>
    <dl class="facts">
      <dt><a href="/sales-orders">Open sales orders</a></dt><dd>{{ $counts['sales_orders'] }}</dd>
      <dt><a href="/purchase-orders">Open purchase orders</a></dt><dd>{{ $counts['purchase_orders'] }}</dd>
      <dt><a href="/sales-invoices">Draft invoices</a></dt><dd>{{ $counts['draft_invoices'] }}</dd>
      <dt><a href="/purchase-bills">Draft bills</a></dt><dd>{{ $counts['draft_bills'] }}</dd>
    </dl>
  </section>
</div>

<div class="columns">
  @foreach ([['Most overdue invoices', $overdue, '/reports/ar-aging', 'AR aging', 'Invoice', 'Customer', '/sales-invoices', 'No overdue invoices.'],
             ['Bills due in the next 14 days', $dueSoon, '/reports/ap-aging', 'AP aging', 'Bill', 'Supplier', '/purchase-bills', 'No bills due soon.']]
            as [$heading, $rows, $report, $reportName, $docLabel, $partyLabel, $base, $empty])
  <section>
    <h2>{{ $heading }} <a class="muted plain small" href="{{ $report }}">{{ $reportName }}</a></h2>
    @if (count($rows))
    <div class="tablewrap"><table class="grid">
      <thead><tr><th>{{ $docLabel }}</th><th>{{ $partyLabel }}</th><th>Due</th><th class="num">Balance</th></tr></thead>
      <tbody>@foreach ($rows as $b)<tr>
        <td><a href="{{ $base }}/{{ $b->id }}">{{ $b->number }}</a></td>
        <td>{{ $b->party }}</td><td>{{ $b->due_date }}</td>
        <td class="num">{{ $b->currency_code }} @amount($b->balance)</td></tr>@endforeach</tbody>
    </table></div>
    @else<p class="empty">{{ $empty }}</p>@endif
  </section>
  @endforeach
</div>
@endsection
