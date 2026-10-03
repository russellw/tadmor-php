@extends('layout', ['title' => $title ?? 'Not found'])
@section('content')
<h1>{{ $title ?? 'Not found' }}</h1>
<p>{{ $message ?? 'There is nothing at this address.' }}</p>
<p><a href="/">Go to the home page</a></p>
@endsection
@section('bare')
<div class="card login"><h1>{{ $title ?? 'Not found' }}</h1><p>{{ $message ?? 'There is nothing at this address.' }}</p><p><a href="/">Go to the home page</a></p></div>
@endsection
