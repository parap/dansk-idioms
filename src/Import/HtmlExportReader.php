<?php declare(strict_types=1);

namespace Dansk\Import;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Reads a Telegram Desktop **HTML** export.
 *
 * The HTML export carries everything the JSON one does, plus one thing it does better:
 * timestamps include a UTC offset ("29.08.2026 21:01:39 UTC+01:00"), where JSON's `date`
 * field is naive local time. Bold survives as <strong> and U+200B survives the round-trip.
 *
 * @return iterable<array{tg_message_id:int, from_name:?string, posted_at:?string,
 *                        posted_at_raw:string, text:string}>
 */
final class HtmlExportReader
{
    public function read(string $file): iterable
    {
        $html = file_get_contents($file);
        if ($html === false) {
            throw new \RuntimeException("Cannot read {$file}");
        }

        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();

        $xp = new DOMXPath($doc);
        $lastFrom = null;

        foreach ($xp->query("//div[contains(@class,'message') and contains(@class,'default')]") as $node) {
            /** @var DOMElement $node */
            $id = $node->getAttribute('id');
            if (!preg_match('/message(\d+)/', $id, $m)) {
                continue;
            }

            $bodyNodes = $xp->query(".//div[@class='text']", $node);
            if ($bodyNodes === false || $bodyNodes->length === 0) {
                continue;
            }

            // "joined" messages omit the sender and inherit the previous one's.
            $fromNode = $xp->query(".//div[@class='from_name']", $node);
            if ($fromNode !== false && $fromNode->length > 0) {
                $lastFrom = trim($fromNode->item(0)->textContent);
            }

            $dateNode = $xp->query(".//div[contains(@class,'date')]", $node);
            $rawDate  = $dateNode !== false && $dateNode->length > 0
                ? trim(($dateNode->item(0) instanceof DOMElement ? $dateNode->item(0)->getAttribute('title') : '') ?: '')
                : '';

            yield [
                'tg_message_id' => (int) $m[1],
                'from_name'     => $lastFrom,
                'posted_at'     => $this->toUtc($rawDate),
                'posted_at_raw' => $rawDate,
                'text'          => $this->flatten($bodyNodes->item(0)),
            ];
        }
    }

    /** Inner HTML -> plain text, with <strong> preserved as sentinels and <br> as newlines. */
    private function flatten(\DOMNode $textDiv): string
    {
        $out = '';
        foreach ($textDiv->childNodes as $child) {
            $out .= $this->flattenNode($child);
        }
        return trim(html_entity_decode($out, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function flattenNode(\DOMNode $node): string
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            return $node->nodeValue ?? '';
        }
        if (!$node instanceof DOMElement) {
            return '';
        }

        $name = strtolower($node->nodeName);
        if ($name === 'br') {
            return "\n";
        }

        $inner = '';
        foreach ($node->childNodes as $child) {
            $inner .= $this->flattenNode($child);
        }

        return in_array($name, ['strong', 'b'], true)
            ? Text::BOLD_OPEN . $inner . Text::BOLD_CLOSE
            : $inner;
    }

    /** "29.08.2026 21:01:39 UTC+01:00" -> "2026-08-29 20:01:39" (UTC). */
    private function toUtc(string $raw): ?string
    {
        if ($raw === '') {
            return null;
        }
        $normalized = str_replace('UTC', '', $raw);
        try {
            return (new \DateTimeImmutable(trim($normalized)))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }
}
