<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $title ?? 'Tadmor' }} · Tadmor</title>
<link rel="stylesheet" href="/app.css">
<script src="/app.js" defer></script>
</head>
<body>
@isset($user)
<input type="checkbox" id="navtoggle" class="navtoggle" aria-hidden="true">
<header class="topbar">
  <label for="navtoggle" class="navbutton">Menu</label>
  <a class="brand" href="/">Tadmor</a>
  <div class="who">
    <span>{{ $user->full_name }}@if ($user->is_admin) <span class="tag">admin</span>@endif</span>
    <form method="post" action="/logout">@token<button class="link">Sign out</button></form>
  </div>
</header>
<div class="shell">
  <nav class="sidebar" aria-label="Main">
    @foreach ($nav as $group)
    <div class="navgroup">
      <div class="navtitle">{{ $group['title'] }}</div>
      @foreach ($group['links'] as $link)<a href="{{ $link['url'] }}"@if ($link['active']) class="active" aria-current="page"@endif>{{ $link['text'] }}</a>@endforeach
    </div>
    @endforeach
  </nav>
  <main>
    @yield('content')
  </main>
</div>
@else
<main class="bare">@yield('bare')</main>
@endisset
</body>
</html>
