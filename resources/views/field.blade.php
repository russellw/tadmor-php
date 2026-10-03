@php($f = $row['field'])
@php($ro = $row['readonly'] || !empty($readonly))
<label class="field{{ $f->type === 'bool' ? ' check' : '' }}">
  @if ($f->type === 'bool')
  <input type="checkbox" name="{{ $f->name }}"@if ($row['value']) checked @endif @if ($ro) disabled @endif> {{ $f->label }}
  @else
  <span>{{ $f->label }}@if ($f->required) <span class="req" aria-hidden="true">*</span>@endif</span>
  @if ($f->type === 'select' || $f->type === 'ref')
  <select name="{{ $f->name }}"@if ($ro) disabled @endif @if ($f->required) required @endif>
    <option value="">{{ $f->required ? 'Choose…' : 'None' }}</option>
    @foreach ($row['choices'] as [$value, $text])<option value="{{ $value }}"@if ((string) $value === (string) $row['value']) selected @endif>{{ $text }}</option>@endforeach
  </select>
  @elseif ($f->type === 'textarea')
  <textarea name="{{ $f->name }}" rows="4"@if ($ro) readonly @endif>{{ $row['value'] }}</textarea>
  @else
  <input name="{{ $f->name }}" value="{{ $row['value'] }}"@if ($ro) readonly @endif
    @switch($f->type)
      @case('date') type="date" @break
      @case('email') type="email" @break
      @case('password') type="password" autocomplete="new-password" @break
      @case('decimal') inputmode="decimal" class="num" pattern="-?[0-9]*\.?[0-9]*" @break
      @case('int') inputmode="numeric" class="num" @break
      @default type="text"
    @endswitch
    @if ($f->required) required @endif>
  @endif
  @endif
  @if ($f->help)<small class="muted">{{ $f->help }}</small>@endif
</label>
