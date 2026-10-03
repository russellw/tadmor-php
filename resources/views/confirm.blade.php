@extends('layout')
@section('content')
<div class="pagehead"><h1>{{ $title }}</h1></div>
<form method="post" class="card form">
  @token
  <p>{{ $question }}</p>
  @if ($error ?? null)<p class="error" role="alert">{{ $error }}</p>@endif
  <div class="actions">
    <button class="danger">{{ $confirm ?? 'Delete' }}</button>
    <a class="button" href="{{ $back }}">Cancel</a>
  </div>
</form>
@endsection
