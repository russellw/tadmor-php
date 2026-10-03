@foreach ($errors as $name => $message)@if ($name !== 'email')<p class="error" role="alert">{{ $message }}</p>@endif @endforeach
