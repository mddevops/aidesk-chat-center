<?php

namespace App\Chat;

use Illuminate\Validation\ValidationException;

class PhoneNumber
{
    public static function normalize(string $value): string
    {
        $trimmed = trim($value);
        if (! preg_match('/^\+?[0-9\s().-]+$/D', $trimmed)) {
            throw ValidationException::withMessages(['phone' => 'Укажите корректный номер телефона.']);
        }
        $digits = preg_replace('/\D/', '', $trimmed);
        if (! str_starts_with($trimmed, '+') && strlen($digits) === 11 && str_starts_with($digits, '8')) {
            $digits = '7'.substr($digits, 1);
        } elseif (strlen($digits) === 10 && ! str_starts_with($trimmed, '+')) {
            $digits = '7'.$digits;
        }
        if (strlen($digits) < 8 || strlen($digits) > 15 || $digits[0] === '0' || (! str_starts_with($trimmed, '+') && (strlen($digits) !== 11 || $digits[0] !== '7'))) {
            throw ValidationException::withMessages(['phone' => 'Укажите номер в международном формате или российский номер из 10–11 цифр.']);
        }

        return '+'.$digits;
    }
}
