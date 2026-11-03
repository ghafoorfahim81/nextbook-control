<?php

namespace App\Support;

/**
 * Phone numbers as WhatsApp wants them: digits only, with the country code,
 * no plus sign (93791234567). People write them every which way, so this reads
 * the common Afghan forms and refuses what it cannot be sure of, rather than
 * sending a message to a number it guessed.
 */
class Phone
{
    public const AFGHANISTAN = '93';

    public static function e164(?string $raw, string $defaultCountry = self::AFGHANISTAN): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        // Persian and Arabic-Indic digits are digits too.
        $latin = strtr($raw, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
        $digits = preg_replace('/\D+/', '', $latin);
        $international = str_starts_with(trim($latin), '+') || str_starts_with($digits, '00');

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if ($international) {
            // Already with a country code: take it as given, if it looks like a number.
            return strlen($digits) >= 10 && strlen($digits) <= 15 ? $digits : null;
        }

        if (str_starts_with($digits, '0')) {
            $digits = $defaultCountry.substr($digits, 1);   // 0791234567  → 93791234567
        } elseif (str_starts_with($digits, $defaultCountry) && strlen($digits) >= 11) {
            // 93791234567, written without the plus
        } elseif (strlen($digits) === 9) {
            $digits = $defaultCountry.$digits;               // 791234567   → 93791234567
        }

        return strlen($digits) >= 10 && strlen($digits) <= 15 ? $digits : null;
    }
}
