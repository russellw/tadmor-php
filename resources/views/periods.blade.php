@extends('layout')
@section('content')
<div class="pagehead">
  <h1>Periods and year-end</h1>
  <div class="actions"><a class="button primary" href="/fiscal-years/new">New fiscal year</a>
    <a class="button" href="/accounting-periods/new">New period</a></div>
</div>
@if ($user->is_admin && ($closable || $reopenable))
<div class="card mb">
  <strong>Year-end</strong>
  <div class="actions mt-s">
    @if ($closable)<a class="button" href="/fiscal-years/{{ $closable->id }}/close">Close {{ $closable->name }}…</a>@endif
    @if ($reopenable)<form method="post" action="/fiscal-years/{{ $reopenable->id }}/reopen">@token<button>Reopen {{ $reopenable->name }}</button></form>@endif
  </div>
  @isset($errors['year'])<p class="error" role="alert">{{ $errors['year'] }}</p>@endisset
</div>
@endif
@forelse ($years as $item)
@php($y = $item['year'])
<h2><a href="/fiscal-years/{{ $y->id }}">{{ $y->name }}</a>
  <span class="muted plain">{{ $y->start_date }} to {{ $y->end_date }}</span>
  <span class="pill s-{{ $y->status }}">@label($y->status)</span></h2>
@if (count($item['periods']))
<div class="tablewrap"><table class="grid">
  <thead><tr><th>Period</th><th>From</th><th>To</th><th>Status</th><th></th></tr></thead>
  <tbody>@foreach ($item['periods'] as $p)<tr>
    <td><a href="/accounting-periods/{{ $p->id }}">{{ $p->name }}</a></td><td>{{ $p->start_date }}</td><td>{{ $p->end_date }}</td>
    <td><span class="pill s-{{ $p->status }}">@label($p->status)</span></td>
    <td><form method="post" action="/accounting-periods/{{ $p->id }}/toggle" class="inline">@token<button class="link">{{ $p->status === 'open' ? 'Close' : 'Reopen' }}</button></form>
      @isset($errors["period-{$p->id}"])<p class="error" role="alert">{{ $errors["period-{$p->id}"] }}</p>@endisset
    </td></tr>@endforeach</tbody>
</table></div>
@else<p class="empty">No periods yet. Posting into this year creates monthly periods as needed.</p>@endif
@empty
<p class="empty">No fiscal years yet. Create one before posting anything.</p>
@endforelse
@endsection
