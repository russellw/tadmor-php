@switch($c->kind)
@case('amount')<span @class(['neg' => \App\Ui\Fmt::isNegative($v)])>@amount($v)</span>@break
@case('qty')@qty($v)@break
@case('bool')@if ($v)Yes@else<span class="muted">No</span>@endif @break
@case('active')@if ($v)<span class="pill ok">Active</span>@else<span class="pill off">Inactive</span>@endif @break
@case('label'){{ \App\Ui\Fmt::label($v) ?: '—' }}@break
@case('status')<span class="pill s-{{ $v }}">@label($v)</span>@break
@default @if ($v === null || $v === '')<span class="muted">—</span>@else{{ $v }}@endif
@endswitch
