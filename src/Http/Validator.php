<?php

declare(strict_types=1);

namespace Challenge\Http;

final class Validator
{
    /** @var array<string, string> */
    private array $errors = [];

    public function nonEmptyString(string $field, mixed $value): static
    {
        if (!is_string($value) || $value === '') {
            $this->errors[$field] = 'Must be a non-empty string.';
        }

        return $this;
    }

    public function intMin(string $field, mixed $value, int $min): static
    {
        if (!is_int($value) || $value < $min) {
            $this->errors[$field] = "Must be an integer greater than or equal to {$min}.";
        }

        return $this;
    }

    public function bool(string $field, mixed $value): static
    {
        if (!is_bool($value)) {
            $this->errors[$field] = 'Must be a boolean.';
        }

        return $this;
    }

    public function date(string $field, mixed $value): static
    {
        if (!$this->isValidDate($value)) {
            $this->errors[$field] = 'Must be a valid YYYY-MM-DD date.';
        }

        return $this;
    }

    public function dateRange(string $field, mixed $from, mixed $to): static
    {
        if ($this->isValidDate($from) && $this->isValidDate($to) && $from > $to) {
            $this->errors[$field] = 'Must be earlier than or equal to "to".';
        }

        return $this;
    }

    public function intBetween(string $field, mixed $value, int $min, int $max): static
    {
        if (!is_int($value) || $value < $min || $value > $max) {
            $this->errors[$field] = "Must be an integer between {$min} and {$max}.";
        }

        return $this;
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }

    private function isValidDate(mixed $value): bool
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }

        [$y, $m, $d] = explode('-', $value);

        return checkdate((int) $m, (int) $d, (int) $y);
    }
}
