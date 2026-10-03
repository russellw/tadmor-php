<?php

namespace App\Http;

use App\Errors\ApiError;
use App\Support\Dates;
use App\Support\Dec;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use stdClass;

/**
 * Typed read access to a JSON object from a request.
 *
 * A value of the wrong JSON type is a 400 (the request cannot be
 * interpreted). A string that does not hold a valid decimal or date is a
 * 422 (spec/api.md §1.4). Absent and null are the same thing.
 */
final class Body
{
    private function __construct(private readonly array $data) {}

    /** The request's JSON object; an empty body counts as {}. */
    public static function from(Request $request): self
    {
        $raw = $request->getContent();
        if (trim($raw) === '') {
            return new self([]);
        }
        try {
            $data = json_decode($raw, false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException $e) {
            throw new ApiError(400, 'invalid JSON body: '.$e->getMessage());
        }

        return self::of($data);
    }

    public static function of(mixed $data): self
    {
        if (! $data instanceof stdClass) {
            throw new ApiError(400, 'request body must be a JSON object');
        }

        return new self(get_object_vars($data));
    }

    public function has(string $name): bool
    {
        return ($this->data[$name] ?? null) !== null;
    }

    public function raw(string $name): mixed
    {
        return $this->data[$name] ?? null;
    }

    public function str(string $name): ?string
    {
        $v = $this->data[$name] ?? null;
        if ($v !== null && ! is_string($v)) {
            throw new ApiError(400, "$name must be a string");
        }

        return $v;
    }

    /** An optional text field: absent, null, and "" all mean null. */
    public function text(string $name): ?string
    {
        $v = $this->str($name);

        return $v === null || $v === '' ? null : $v;
    }

    public function requiredStr(string $name): string
    {
        $v = $this->str($name);
        if ($v === null || $v === '') {
            throw new ApiError(400, "$name is required");
        }

        return $v;
    }

    public function int(string $name): ?int
    {
        $v = $this->data[$name] ?? null;
        if ($v !== null && ! is_int($v)) {
            throw new ApiError(400, "$name must be an integer");
        }

        return $v;
    }

    public function requiredId(string $name): int
    {
        $v = $this->int($name);
        if ($v === null || $v <= 0) {
            throw new ApiError(400, "$name is required");
        }

        return $v;
    }

    public function bool(string $name): bool
    {
        $v = $this->data[$name] ?? null;
        if ($v === null) {
            return false;
        }
        if (! is_bool($v)) {
            throw new ApiError(400, "$name must be a boolean");
        }

        return $v;
    }

    public function decimal(string $name, array $kind = Dec::MONEY, ?BigDecimal $default = null): ?BigDecimal
    {
        $v = $this->str($name);
        if ($v === null || $v === '') {
            return $default;
        }

        return Dec::parse($v, $kind, $name);
    }

    public function date(string $name): ?string
    {
        $v = $this->str($name);

        return $v === null || $v === '' ? null : Dates::parse($v, $name);
    }

    /** @return list<self> */
    public function list(string $name): array
    {
        $v = $this->data[$name] ?? null;
        if ($v === null) {
            return [];
        }
        if (! is_array($v)) {
            throw new ApiError(400, "$name must be an array");
        }

        return array_map(self::of(...), $v);
    }
}
