<?php declare(strict_types=1);

namespace Dansk\Import;

use Normalizer as IntlNormalizer;

final class Normalizer
{
    /**
     * Dedup key for a Danish term.
     *
     * Deliberately does NOT ASCII-fold ae/oe/aa: "har" and "haar" are different words,
     * which is also why the column is utf8mb4_0900_as_cs. NFC comes first because
     * macOS-sourced text arrives decomposed and would otherwise duplicate silently.
     */
    public static function term(string $s): string
    {
        $s = IntlNormalizer::normalize($s, IntlNormalizer::FORM_C) ?: $s;
        $s = Text::stripBoldMarkers($s);

        // Invisible characters that would otherwise split identical terms.
        $s = preg_replace('/[\x{200B}\x{FEFF}\x{00AD}]/u', '', $s) ?? $s;
        $s = str_replace("\u{00A0}", ' ', $s);

        // Typographic apostrophes -> ASCII, so "ikk'" matches whichever form was typed.
        $s = str_replace(['’', '‘', 'ʼ', '`'], "'", $s);

        // Parenthetical asides live in term_note, not in the key.
        $s = preg_replace('/\([^()]*\)|\[[^\[\]]*\]/u', ' ', $s) ?? $s;

        $s = mb_strtolower($s, 'UTF-8');
        $s = Text::trimPunctuation($s);
        $s = Text::collapseWhitespace($s);

        // Infinitive marker dropped so "at sla sig sammen" and "sla sig sammen" collide.
        $s = preg_replace('/^at\s+/u', '', $s) ?? $s;

        return Text::collapseWhitespace($s);
    }

    /** Normalization for translation text, used for near-duplicate detection. */
    public static function translation(string $s): string
    {
        $s = IntlNormalizer::normalize($s, IntlNormalizer::FORM_C) ?: $s;
        $s = Text::stripBoldMarkers($s);
        $s = preg_replace('/[\x{200B}\x{FEFF}\x{00AD}]/u', '', $s) ?? $s;
        $s = mb_strtolower($s, 'UTF-8');
        $s = str_replace('ё', 'е', $s);            // Russian writers use both interchangeably
        $s = Text::trimPunctuation($s);
        return Text::collapseWhitespace($s);
    }
}
