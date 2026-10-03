<tr class="line">
  <td><select name="line_product_id[]" class="product"><option value="">—</option>@foreach ($productChoices as [$v, $t])<option value="{{ $v }}"@if ($l && (string) $v === (string) ($l['product_id'] ?? '')) selected @endif>{{ $t }}</option>@endforeach</select></td>
  <td><input name="line_description[]" class="desc" value="{{ $l['description'] ?? '' }}"></td>
  <td><input name="line_quantity[]" class="num narrow qty" inputmode="decimal" value="{{ ($l['quantity'] ?? '') ?: '1' }}"></td>
  <td><input name="line_price[]" class="num narrow price" inputmode="decimal" value="{{ $l['price'] ?? '' }}"></td>
  <td><select name="line_account[]" class="account"><option value="">Product default</option>@foreach ($accountChoices as [$v, $t])<option value="{{ $v }}"@if ($l && (string) $v === (string) ($l['account'] ?? '')) selected @endif>{{ $t }}</option>@endforeach</select></td>
  <td><select name="line_tax_code[]" class="taxcode"><option value="">—</option>@foreach ($taxChoices as [$v, $t])<option value="{{ $v }}"@if ($l && (string) $v === (string) ($l['tax_code'] ?? '')) selected @endif>{{ $v }}</option>@endforeach</select></td>
  <td><input name="line_tax_rate[]" class="num narrow rate" inputmode="decimal" value="{{ ($l['tax_rate'] ?? '') ?: '0' }}"></td>
  <td class="num out-subtotal"></td><td class="num out-tax"></td><td class="num out-total"></td>
  <td><button type="button" class="link remove">Remove</button></td>
</tr>
