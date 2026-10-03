@extends('layout')
@section('content')
<div class="pagehead">
  <h1>{{ $title }}</h1>
  @if ($new)<a class="button primary" href="{{ $new[0] }}">{{ $new[1] }}</a>@endif
</div>
@if ($rows)
<div class="tablewrap">
<table class="grid{{ $rows[0]['link'] ? ' clickable' : '' }}">
  <thead><tr>@foreach ($columns as $c)<th @class(['num' => $c->numeric])>{{ $c->label }}</th>@endforeach</tr></thead>
  <tbody>
  @foreach ($rows as $row)
  <tr>
    @foreach ($row['cells'] as $i => [$c, $v])
    <td @class(['num' => $c->numeric])>@if ($i === 0 && $row['link'])<a href="{{ $row['link'] }}">@endif @include('cell')@if ($i === 0 && $row['link'])</a>@endif</td>
    @endforeach
  </tr>
  @endforeach
  </tbody>
</table>
</div>
@else
<p class="empty">{{ $empty }}</p>
@endif
@endsection
