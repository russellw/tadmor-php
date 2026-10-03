@extends('layout')
@section('content')
<div class="pagehead"><h1>{{ $title }}</h1></div>
<form method="post" class="card form">
  @token
  @if (!empty($readonly))<p class="muted">Only administrators can change these.</p>@endif
  @if (!empty($notice))<p class="notice" role="status">{{ $notice }}</p>@endif
  @foreach ($form as $row)@include('field')@endforeach
  @if ($error)<p class="error" role="alert">{{ $error }}</p>@endif
  <div class="actions">
    @if (empty($readonly))<button class="primary">Save</button>@endif
    <a class="button" href="{{ $back }}">{{ empty($readonly) ? 'Cancel' : 'Back' }}</a>
  </div>
</form>
@foreach ($links ?? [] as [$url, $text])<p><a href="{{ $url }}">{{ $text }}</a></p>@endforeach
@endsection
