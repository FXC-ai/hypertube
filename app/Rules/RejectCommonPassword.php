<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class RejectCommonPassword implements ValidationRule
{
    /**
     * @var list<string>
     */
    private const COMMON_PASSWORDS = [
        'password',
        'password1',
        'motdepasse',
        'motdepassee',
        'bonjour',
        'soleil',
        'ordinateur',
        'fromage',
        'papillon',
        'azerty',
        'azertyuiop',
        'iloveyou',
        'admin',
        'administrator',
        'root',
        'secret',
        'hypertube',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $normalized = preg_replace('/[^a-z]/', '', mb_strtolower($value)) ?? '';

        if ($normalized !== '' && in_array($normalized, self::COMMON_PASSWORDS, true)) {
            $fail('This password is too common. Use a longer password with uppercase letters, digits and symbols.');
        }
    }
}
