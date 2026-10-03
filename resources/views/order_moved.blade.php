@extends('layout')
@section('content')
<div class="pagehead"><h1>{{ $title }}</h1></div>
<div class="card">
  <p class="notice">Created {{ count($movements) }} draft stock movement{{ count($movements) == 1 ? '' : 's' }}. Post each one to record it in the ledger.</p>
  <ul>@foreach ($movements as $mid)<li><a href="/stock-movements/{{ $mid }}">Stock movement {{ $mid }}</a></li>@endforeach</ul>
  <p><a href="{{ $back }}">Back to the order</a></p>
</div>
@endsection
