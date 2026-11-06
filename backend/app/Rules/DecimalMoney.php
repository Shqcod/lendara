<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class DecimalMoney implements ValidationRule
{
    public function __construct(private bool $positive = false) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // DECIMAL(18,2): sixteen integer digits, no float conversion or credit limit.
        if (! is_string($value) || ! preg_match('/\A(?:0|[1-9][0-9]{0,15})(?:\.[0-9]{1,2})?\z/', $value)) {
            $fail('Gunakan string desimal tanpa pemisah ribuan, maksimal 16 digit sebelum dan 2 digit setelah titik.');
        } elseif ($this->positive && ! preg_match('/[1-9]/', $value)) {
            $fail('Nilai yang diisi harus lebih dari nol.');
        }
    }
}
