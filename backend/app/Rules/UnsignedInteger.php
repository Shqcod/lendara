<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class UnsignedInteger implements ValidationRule
{
    public function __construct(private int $minimum = 0) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_int($value) || $value < $this->minimum || $value > 4294967295) {
            $fail('Gunakan bilangan bulat antara '.$this->minimum.' dan 4294967295 sesuai kapasitas penyimpanan.');
        }
    }
}
