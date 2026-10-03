@forelse ($rows as $r)<tr><td><a href="/accounts/{{ $r['account_id'] }}/ledger">{{ $r['code'] }}</a></td><td>{{ $r['name'] }}</td><td class="num">@amount($r['amount'])</td></tr>
@empty<tr><td colspan="3" class="muted">No activity.</td></tr>@endforelse
