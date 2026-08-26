<?php

declare(strict_types=1);

namespace App\Core\Export;

/**
 * Sanitize cell values to prevent CSV formula injection (CWE-1236).
 *
 * Excel, Google Sheets, and LibreOffice Calc evaluate cells starting with
 * =, +, -, @, TAB, or CR as formulas. An attacker who controls user-generated
 * content (e.g. product names, form submissions, subscriber emails) could embed
 * payloads like =HYPERLINK("evil.com","Click") or =cmd|'/c calc'!A1 that execute
 * when a merchant opens the exported file.
 *
 * Mitigation: prefix dangerous cells with a single-quote (') which spreadsheet
 * apps strip on display but which prevents formula evaluation. This is the
 * industry-standard approach (OWASP CSV Injection guidance).
 */
class CsvSanitizer
{
    /**
     * Characters that trigger formula evaluation in major spreadsheet apps.
     * TAB (\t) and CR (\r) are included per OWASP guidance.
     */
    private const DANGEROUS_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * Escape a single cell value.
     * Non-string types are cast to string first; null → empty string.
     */
    public static function escape(mixed $value): string
    {
        $str = (string) $value;

        if ($str === '') {
            return $str;
        }

        if (in_array($str[0], self::DANGEROUS_PREFIXES, true)) {
            return "'".$str;
        }

        return $str;
    }

    /**
     * Escape all values in a CSV row array.
     * Use this as a drop-in wrapper before every fputcsv() call.
     *
     * @param  mixed[]  $row
     * @return string[]
     */
    public static function escapeRow(array $row): array
    {
        return array_map([self::class, 'escape'], $row);
    }
}
