@extends('layout')
@section('content')
<div class="pagehead"><h1>{{ $title }}</h1></div>
<form method="post" class="card form wide" id="docform">
  @token
  <div class="columns">
    @foreach ($header as $row)@include('field')@endforeach
  </div>
  <h2>Lines</h2>
  <div class="tablewrap">
  <table class="grid lines" id="lines">
    <thead><tr>
      <th>Product</th><th>Description *</th><th class="num">Quantity</th><th class="num">{{ $priceLabel }}</th>
      <th>{{ $accountLabel }}</th><th>Tax code</th><th class="num">Tax %</th>
      <th class="num">Subtotal</th><th class="num">Tax</th><th class="num">Total</th><th></th>
    </tr></thead>
    <tbody>
    @foreach ($lines as $l)@include('line_row')@endforeach
    </tbody>
  </table>
  </div>
  <template id="line-template">@include('line_row', ['l' => null])</template>
  <div class="actions"><button type="button" id="add-line">Add line</button>
    @if ($isOrder)<span class="muted">Order quantities must be greater than zero.</span>@endif</div>
  <table class="totals">
    <tr><td>Subtotal</td><td class="num" id="sum-subtotal"></td></tr>
    <tr><td>Tax</td><td class="num" id="sum-tax"></td></tr>
    <tr><td><strong>Total</strong></td><td class="num"><strong id="sum-total"></strong></td></tr>
  </table>
  @if ($error)<p class="error" role="alert">{{ $error }}</p>@endif
  <div class="actions">
    <button class="primary">Save draft</button>
    <a class="button" href="{{ $back }}">Cancel</a>
  </div>
</form>
<script type="application/json" id="client-data">@json($clientData)</script>
@endsection
