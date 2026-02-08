<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class RomanianCui implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (empty($value)) {
            return;
        }

        // Strip RO prefix if present
        $cui = strtoupper(trim($value));
        if (str_starts_with($cui, 'RO')) {
            $cui = substr($cui, 2);
        }

        // Must be 2-10 digits
        if (!preg_match('/^\d{2,10}$/', $cui)) {
            $fail(__('The :attribute must be a valid Romanian CUI (2-10 digits, optionally prefixed with RO).'));
            return;
        }

        // CUI checksum validation
        $weights = [7, 5, 3, 2, 1, 7, 5, 3, 2];
        $digits = array_map('intval', str_split($cui));
        $checkDigit = array_pop($digits);

        // Pad to 9 digits from the left
        while (count($digits) < 9) {
            array_unshift($digits, 0);
        }

        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += $digits[$i] * $weights[$i];
        }

        $remainder = ($sum * 10) % 11;
        $expected = $remainder === 10 ? 0 : $remainder;

        if ($checkDigit !== $expected) {
            $fail(__('The :attribute checksum is invalid.'));
        }
    }
}
