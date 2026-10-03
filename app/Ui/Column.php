<?php

namespace App\Ui;

use Closure;

/** A list column: a row key or a function of the row, and how to show it. */
final class Column
{
    /** @param string $kind text, amount, qty, bool, active, label, status */
    public function __construct(
        public string $label,
        public string|Closure $key,
        public bool $numeric = false,
        public string $kind = 'text',
    ) {}

    public function value(array $row): mixed
    {
        return $this->key instanceof Closure ? ($this->key)($row) : ($row[$this->key] ?? null);
    }
}
