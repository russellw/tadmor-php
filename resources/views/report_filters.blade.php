<form method="get" class="filters">
  @foreach ($filters as [$name, $text])<label>{{ $text }} <input type="date" name="{{ $name }}" value="{{ is_string($params[$name] ?? null) ? $params[$name] : '' }}"></label>@endforeach
  <button>Show</button>
  <span class="muted">Leave a date blank for no bound.</span>
</form>
@if ($error)<p class="error" role="alert">{{ $error }}</p>@endif
