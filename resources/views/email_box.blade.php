<details @if (isset($errors['email']) || $emailResult) open @endif>
  <summary>Email</summary>
  <form method="post" action="{{ $emailUrl }}" class="actions mt-s">
    @token
    <input class="wide-input" name="to" placeholder="Recipients, comma separated (blank: the counterparty's email on file)">
    <button>Send</button>
  </form>
  @isset($errors['email'])<p class="error" role="alert">{{ $errors['email'] }}</p>@endisset
  @if ($emailResult)<p class="notice" role="status">Sent to {{ implode(', ', $emailResult) }}.</p>@endif
</details>
