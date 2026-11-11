<?php

namespace App\Modules\Invoicing\Support;

/**
 * GSTIN format and checksum validation (15 chars: 2-digit state, 10-char PAN, entity number, 'Z', check character).
 * Checks run in a fixed order and the first failure is reported: length, invalid_character, invalid_state_code,
 * invalid_pan, invalid_entity, invalid_z, checksum_mismatch. Upper case only (callers normalise before validating).
 * Format validity says the number is well formed; it does not say the GSTIN is registered or active.
 */
final class Gstin
{
    private const CHARSET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    /** @return string|null null when valid, otherwise the error kind */
    public static function error(string $gstin): ?string
    {
        if (strlen($gstin) !== 15) {
            return 'length';
        }
        if (! preg_match('/^[0-9A-Z]{15}$/D', $gstin)) {
            return 'invalid_character';
        }
        if (! ctype_digit(substr($gstin, 0, 2)) || ! GstStateCodes::exists((int) substr($gstin, 0, 2))) {
            return 'invalid_state_code';
        }
        if (! preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/D', substr($gstin, 2, 10))) {
            return 'invalid_pan';
        }
        if (! preg_match('/^[1-9A-Z]$/D', $gstin[12])) {
            return 'invalid_entity';
        }
        if ($gstin[13] !== 'Z') {
            return 'invalid_z';
        }

        return self::checkChar(substr($gstin, 0, 14)) === $gstin[14] ? null : 'checksum_mismatch';
    }

    public static function isValid(string $gstin): bool
    {
        return self::error($gstin) === null;
    }

    /** State code (1-99) of a valid GSTIN, else null. */
    public static function stateCode(string $gstin): ?int
    {
        return self::isValid($gstin) ? (int) substr($gstin, 0, 2) : null;
    }

    /** Base-36 weighted checksum character for the first 14 characters. */
    public static function checkChar(string $first14): string
    {
        $sum = 0;
        foreach (str_split($first14) as $i => $char) {
            $value = strpos(self::CHARSET, $char) * ($i % 2 === 0 ? 1 : 2);
            $sum += intdiv($value, 36) + $value % 36;
        }

        return self::CHARSET[(36 - $sum % 36) % 36];
    }
}
