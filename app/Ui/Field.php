<?php

namespace App\Ui;

use Closure;

/** One field of a declarative form; the form posts plain HTML values. */
final class Field
{
    /**
     * @param  string  $type  text, email, password, textarea, decimal, int, date, bool, select, ref
     * @param  Closure|null  $choices  for select and ref: returns [[value, label], ...]
     */
    public function __construct(
        public string $name,
        public string $label,
        public string $type = 'text',
        public ?Closure $choices = null,
        public bool $required = false,
        public string $help = '',
        public bool $readonlyOnEdit = false,
    ) {}

    /** An HTML form value in the API body's JSON shape. */
    public function toJson(mixed $raw): mixed
    {
        if ($this->type === 'bool') {
            return $raw === 'on';
        }
        if (is_int($raw) || is_float($raw)) {
            $raw = (string) $raw;
        }
        if (! is_string($raw)) {
            return null;
        }
        $raw = $this->type === 'password' ? $raw : trim($raw);
        if ($raw === '') {
            return null;
        }
        if (in_array($this->type, ['int', 'ref'], true)) {
            return ctype_digit($raw) && strlen($raw) <= 9 ? (int) $raw : $raw; // Body refuses a non-integer clearly
        }

        return $raw;
    }
}
