<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class EmailOrPhone implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $isEmail = filter_var($value, FILTER_VALIDATE_EMAIL) && str_contains($value, '.');

        if ($isEmail) {
            return;
        }

        if (is_string($value) && preg_match('/^0[0-9]{9,10}$/', $value)) {
            return;
        }

        $fail(__('api.auth.invalid_identifier'));
    }
}
