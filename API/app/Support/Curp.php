<?php

namespace App\Support;

use DateTimeImmutable;

final class Curp
{
    private const CHARACTER_DICTIONARY = '0123456789ABCDEFGHIJKLMNÑOPQRSTUVWXYZ';

    private const PATTERN = '/^[A-Z][AEIOUX][A-Z]{2}\d{2}(?:0[1-9]|1[0-2])(?:0[1-9]|[12]\d|3[01])[HM](?:AS|BC|BS|CC|CL|CM|CS|CH|DF|DG|GT|GR|HG|JC|MC|MN|MS|NT|NL|OC|PL|QT|QR|SP|SL|SR|TC|TS|TL|VZ|YN|ZS|NE)[B-DF-HJ-NP-TV-Z]{3}[A-J0-9]\d$/';

    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = strtoupper(trim($value));

        return $normalized === '' ? null : $normalized;
    }

    public static function isValid(?string $value): bool
    {
        if ($value === null || preg_match(self::PATTERN, $value) !== 1) {
            return false;
        }

        if (! self::hasValidBirthDate($value)) {
            return false;
        }

        $sum = 0;

        for ($index = 0; $index < 17; $index++) {
            $characterValue = mb_strpos(self::CHARACTER_DICTIONARY, $value[$index]);

            if ($characterValue === false) {
                return false;
            }

            $sum += ($characterValue % 10) * (18 - $index);
        }

        return (int) $value[17] === (10 - ($sum % 10)) % 10;
    }

    private static function hasValidBirthDate(string $value): bool
    {
        $century = ctype_digit($value[16]) ? '19' : '20';
        $date = DateTimeImmutable::createFromFormat('!Ymd', $century.substr($value, 4, 6));
        $errors = DateTimeImmutable::getLastErrors();

        return $date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0));
    }
}
