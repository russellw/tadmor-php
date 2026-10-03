<?php

namespace App\Ui;

use App\Http\Body;
use Illuminate\Http\Request;

/** A list of fields with their current values, turned into an API body on submit. */
final class Form
{
    /** @param list<Field> $fields */
    public function __construct(public array $fields, public array $values = [], public bool $editing = false) {}

    public function body(Request $request, array $extra = []): Body
    {
        $data = [];
        foreach ($this->fields as $f) {
            $data[$f->name] = $this->editing && $f->readonlyOnEdit
                ? ($this->values[$f->name] ?? null)
                : $f->toJson($request->input($f->name));
        }

        return Body::of((object) ($extra + $data));
    }

    /** Re-display what the user typed after a refused submission. */
    public function keep(Request $request): void
    {
        foreach ($this->fields as $f) {
            if (! ($this->editing && $f->readonlyOnEdit)) {
                $this->values[$f->name] = $f->type === 'bool' ? $request->input($f->name) === 'on' : (string) $request->input($f->name, '');
            }
        }
    }

    /** Fields paired with their current values and choices, for the template. */
    public function rows(): array
    {
        $out = [];
        foreach ($this->fields as $f) {
            $value = $this->values[$f->name] ?? null;
            $choices = null;
            if ($f->choices !== null) {
                $choices = ($f->choices)();
                // Keep an inactive current value selectable rather than losing it.
                if ($value !== null && $value !== '' && ! in_array((string) $value, array_map(fn ($c) => (string) $c[0], $choices), true)) {
                    $choices[] = [$value, "$value (inactive)"];
                }
            }
            $out[] = ['field' => $f, 'value' => $value === null ? '' : $value, 'choices' => $choices,
                'readonly' => $this->editing && $f->readonlyOnEdit];
        }

        return $out;
    }
}
