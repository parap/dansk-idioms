<?php declare(strict_types=1);

namespace Dansk\Import;

/**
 * Shared character-level helpers.
 *
 * Every offset in the importer is a CHARACTER offset. preg_* with PREG_OFFSET_CAPTURE
 * returns BYTE offsets even under /u, so anything crossing that boundary goes through
 * byteToChar() here and nowhere else.
 */
final class Text
{
    public const ZWSP = "\u{200B}";

    /** Sentinels marking where <strong> ran, so bold survives plain-text processing. */
    public const BOLD_OPEN  = "\x02";
    public const BOLD_CLOSE = "\x03";

    public static function byteToChar(string $subject, int $byteOffset): int
    {
        return mb_strlen(substr($subject, 0, $byteOffset), 'UTF-8');
    }

    public static function hasCyrillic(string $s): bool
    {
        return (bool) preg_match('/\p{Cyrillic}/u', $s);
    }

    public static function hasLatin(string $s): bool
    {
        return (bool) preg_match('/\p{Latin}/u', $s);
    }

    /** 'cyr', 'lat' or null, from the first cased letter in the string. */
    public static function openingScript(string $s): ?string
    {
        if (!preg_match('/\p{Cyrillic}|\p{Latin}/u', $s, $m)) {
            return null;
        }
        return preg_match('/\p{Cyrillic}/u', $m[0]) ? 'cyr' : 'lat';
    }

    /**
     * Replace balanced parentheticals with a filler of identical CHARACTER length,
     * so offsets into the masked string map straight back onto the original.
     */
    public static function maskParentheticals(string $s): string
    {
        return preg_replace_callback(
            '/\([^()]*\)|\[[^\[\]]*\]/u',
            static fn(array $m): string => str_repeat("\x01", mb_strlen($m[0], 'UTF-8')),
            $s
        ) ?? $s;
    }

    public static function stripBoldMarkers(string $s): string
    {
        return str_replace([self::BOLD_OPEN, self::BOLD_CLOSE], '', $s);
    }

    /**
     * UTF-8-safe punctuation trim.
     *
     * trim($s, "…«»") is BYTE-based: PHP strips the individual bytes of those
     * multibyte characters, and '…' (E2 80 A6) contributes 0x80 -- the trailing byte
     * of many Cyrillic letters. Trimming then cuts 'р' (D1 80) in half and leaves a
     * dangling 0xD1, which MySQL rejects with "Incorrect string value". Never use
     * trim() with a character list on UTF-8 text.
     */
    public static function trimPunctuation(string $s, bool $withDashes = true): string
    {
        $class = $withDashes
            ? '[\s.,;:!?…"«»„“”\-–—*\x{00A0}]'
            : '[\s.,;:!?…"«»„“”\x{00A0}]';
        return preg_replace('/^' . $class . '+|' . $class . '+$/u', '', $s) ?? $s;
    }

    public static function collapseWhitespace(string $s): string
    {
        return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
    }

    /**
     * Word count over letter-runs; works for both Cyrillic and Latin.
     * preg_match_all returns false on engine limits (backtracking, bad UTF-8), so the
     * result is coerced -- a miscount must never abort an import mid-corpus.
     */
    public static function wordCount(string $s): int
    {
        return (int) preg_match_all('/[\p{L}\p{N}\'’\-]+/u', $s);
    }

    /** Share of cased letters that are Latin -- used to reject quoted Danish. */
    public static function latinRatio(string $s): float
    {
        $lat = (int) preg_match_all('/\p{Latin}/u', $s);
        $cyr = (int) preg_match_all('/\p{Cyrillic}/u', $s);
        $total = $lat + $cyr;
        return $total === 0 ? 0.0 : $lat / $total;
    }
}
