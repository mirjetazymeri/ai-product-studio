<?php

namespace App\Services\Catalog;

use DateInterval;
use DateTimeInterface;

final class CellSanitizer
{
    private const FORMULA_TRIGGERS = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * Stops spreadsheet software from running untrusted text as a formula, by
     * prefixing an apostrophe. Numbers are left alone, as they can't be formulas.
     */
    public static function forExport(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        return in_array(ltrim($value, ' ')[0] ?? '', self::FORMULA_TRIGGERS, true) ? "'".$value : $value;
    }

    /**
     * Turns a raw spreadsheet cell into clean text, or null if it is empty.
     */
    public static function fromImport(mixed $value): ?string
    {
        $text = match (true) {
            $value === null, $value instanceof DateInterval => '',
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
            is_bool($value) => $value ? '1' : '0',
            is_float($value) && floor($value) === $value && abs($value) < 1e15 => (string) (int) $value,
            default => (string) $value,
        };

        $text = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', mb_scrub($text, 'UTF-8')));

        // Undoes forExport, so a file exported from this app imports back unchanged.
        if (strlen($text) > 1 && $text[0] === "'" && in_array($text[1], self::FORMULA_TRIGGERS, true)) {
            $text = substr($text, 1);
        }

        return $text === '' ? null : $text;
    }
}
