@extends('layout')
@section('content')
<div class="pagehead"><h1>{{ $title }}</h1></div>
<form method="post" class="card form">
  @token
  <p>Closing {{ $year->name }} ({{ $year->start_date }} to {{ $year->end_date }}) will:</p>
  <ul>
    <li>post a closing entry dated {{ $year->end_date }} that moves every revenue and expense balance into the retained earnings account below;</li>
    <li>close every period of the year, and then the year, so nothing more can post into it;</li>
    <li>create the next fiscal year, if none covers the following day.</li>
  </ul>
  <p class="muted">An administrator can reopen the year later; that reverses the closing entry.</p>
  <label class="field"><span>Retained earnings account <span class="req">*</span></span>
    <select name="retained_earnings_account_id" required>@foreach ($equityAccounts as [$v, $t])<option value="{{ $v }}"@if ((string) $v === (string) $chosen) selected @endif>{{ $t }}</option>@endforeach</select></label>
  @if ($error)<p class="error" role="alert">{{ $error }}</p>@endif
  <div class="actions"><button class="danger">Close {{ $year->name }}</button><a class="button" href="/periods">Cancel</a></div>
</form>
@endsection
