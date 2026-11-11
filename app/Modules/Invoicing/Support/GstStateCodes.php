<?php

namespace App\Modules\Invoicing\Support;

/**
 * GST state / UT codes (the first two digits of a GSTIN). Mirrors tests/fixtures/india-data/gst-state-codes.json,
 * which a Pest test checks entry by entry. Retired codes (25, 28) appear in historical GSTINs and are accepted when
 * validating, but are never offered or returned from a name lookup. 97 = Other Territory, 99 = Centre Jurisdiction.
 */
final class GstStateCodes
{
    /** @var array<int, array{0: string, 1: bool}> code => [name, retired] */
    private const STATES = [
        1 => ['Jammu and Kashmir', false],
        2 => ['Himachal Pradesh', false],
        3 => ['Punjab', false],
        4 => ['Chandigarh', false],
        5 => ['Uttarakhand', false],
        6 => ['Haryana', false],
        7 => ['Delhi', false],
        8 => ['Rajasthan', false],
        9 => ['Uttar Pradesh', false],
        10 => ['Bihar', false],
        11 => ['Sikkim', false],
        12 => ['Arunachal Pradesh', false],
        13 => ['Nagaland', false],
        14 => ['Manipur', false],
        15 => ['Mizoram', false],
        16 => ['Tripura', false],
        17 => ['Meghalaya', false],
        18 => ['Assam', false],
        19 => ['West Bengal', false],
        20 => ['Jharkhand', false],
        21 => ['Odisha', false],
        22 => ['Chhattisgarh', false],
        23 => ['Madhya Pradesh', false],
        24 => ['Gujarat', false],
        25 => ['Daman and Diu', true],
        26 => ['Dadra and Nagar Haveli and Daman and Diu', false],
        27 => ['Maharashtra', false],
        28 => ['Andhra Pradesh (before 2014 split)', true],
        29 => ['Karnataka', false],
        30 => ['Goa', false],
        31 => ['Lakshadweep', false],
        32 => ['Kerala', false],
        33 => ['Tamil Nadu', false],
        34 => ['Puducherry', false],
        35 => ['Andaman and Nicobar Islands', false],
        36 => ['Telangana', false],
        37 => ['Andhra Pradesh', false],
        38 => ['Ladakh', false],
        97 => ['Other Territory', false],
        99 => ['Centre Jurisdiction', false],
    ];

    /** Names that are not the official one but are common in real data (old names, ampersand forms, merged UTs). */
    /** Keys are in self::key() form; keep in step with ALIASES in shared/india-data/crates/india-data/src/gst.rs. */
    private const ALIASES = [
        'NCTOFDELHI' => 7,
        'NEWDELHI' => 7,
        'ORISSA' => 21,
        'UTTARANCHAL' => 5,
        'PONDICHERRY' => 34,
        'CHATTISGARH' => 22,
        'DAMANANDDIU' => 26,
        'DADRAANDNAGARHAVELI' => 26,
        'DADRANAGARHAVELI' => 26,
        'DAMANDIU' => 26,
        'DADRAANDNAGARHAVELIDAMANANDDIU' => 26,
        'DNHDD' => 26,
        'ANDAMANNICOBAR' => 35,
        'ANDAMANANDNICOBAR' => 35,
        'ANDAMANNICOBARISLANDS' => 35,
        'JAMMUKASHMIR' => 1,
        'LAKSHDWEEP' => 31,
        'TELENGANA' => 36,
        'THEDADRAANDNAGARHAVELIANDDAMANANDDIU' => 26,
        'ARUNANCHALPRADESH' => 12,
    ];

    /** @return array<int, string> every code (including retired) => name */
    public static function all(): array
    {
        return array_map(fn (array $s) => $s[0], self::STATES);
    }

    /** @return array<int, string> codes a user may pick today (no retired codes, no 99 Centre Jurisdiction) */
    public static function selectable(): array
    {
        return array_map(fn (array $s) => $s[0], array_filter(self::STATES, fn (array $s, int $code) => ! $s[1] && $code !== 99, ARRAY_FILTER_USE_BOTH));
    }

    public static function exists(int $code): bool
    {
        return isset(self::STATES[$code]);
    }

    public static function isRetired(int $code): bool
    {
        return self::STATES[$code][1] ?? false;
    }

    public static function name(int $code): ?string
    {
        return self::STATES[$code][0] ?? null;
    }

    /** Two-digit string form ("07"), or null when the value is not a known code. Accepts 7, "7" and "07". */
    public static function normalize(int|string|null $code): ?string
    {
        if ($code === null || ! preg_match('/^\d{1,2}$/', (string) $code) || ! self::exists((int) $code)) {
            return null;
        }

        return str_pad((string) (int) $code, 2, '0', STR_PAD_LEFT);
    }

    /** Case, spacing and punctuation insensitive; "&" = "and". Retired codes are never returned. */
    public static function codeForName(string $name): ?int
    {
        $key = self::key($name);
        if ($key === '') {
            return null;
        }
        if (isset(self::ALIASES[$key])) {
            return self::ALIASES[$key];
        }
        foreach (self::STATES as $code => [$official, $retired]) {
            if (! $retired && self::key($official) === $key) {
                return $code;
            }
        }

        return null;
    }

    /** Same as the Rust canonical key: ASCII upper case, "&" = "AND", every byte that is not an ASCII letter or digit dropped. */
    private static function key(string $name): string
    {
        return (string) preg_replace('/[^A-Z0-9]/', '', strtoupper(str_replace('&', 'AND', $name)));
    }
}
