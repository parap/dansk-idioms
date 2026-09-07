<?php declare(strict_types=1);

namespace Dansk\Domain\Reading;

use Dansk\Import\Text;
use Dansk\Support\Db;
use Throwable;

/**
 * Authoring and retrieval of reading passages.
 *
 * Every check lives here rather than in the UI, because authored content also arrives
 * from the command line, and a paper that cannot be graded must be refused at the point
 * it is written rather than discovered by a learner halfway through a timed round.
 */
final class ReadingRepository
{
    /** Points per correct answer, as the exam awards them for each task type. */
    private const POINTS = ['mc' => 2, 'insert' => 2, 'cloze' => 1];

    /** Task types whose questions are holes in the text rather than separate prompts. */
    private const GAP_KINDS = ['insert', 'cloze'];

    private const MARKER = '/\{\{(\d+)\}\}/';

    /**
     * @param  array<string,mixed> $doc
     * @throws InvalidPassage when the document could not produce a gradable paper
     */
    public function save(array $doc): int
    {
        $this->validate($doc);

        $pdo = Db::pdo();
        $pdo->beginTransaction();

        try {
            $passageId = $this->insertPassage($doc);
            $bank      = $this->insertBank($passageId, $doc);

            foreach ($doc['items'] as $item) {
                $itemId = $this->insertItem($passageId, $doc['kind'], $item);
                $this->attachAnswer($passageId, $itemId, $doc['kind'], $item, $bank);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return $passageId;
    }

    public function publish(int $passageId): void
    {
        Db::execute('UPDATE reading_passages SET is_published = 1 WHERE id = ?', [$passageId]);
    }

    public function unpublish(int $passageId): void
    {
        Db::execute('UPDATE reading_passages SET is_published = 0 WHERE id = ?', [$passageId]);
    }

    /** @return list<array<string,mixed>> */
    public function publishedByKind(string $kind): array
    {
        return Db::fetchAll(
            'SELECT id, slug, kind, title, body, word_count FROM reading_passages
             WHERE is_published = 1 AND kind = ? ORDER BY id',
            [$kind]
        );
    }

    // ---- writing -----------------------------------------------------------

    /** @param array<string,mixed> $doc */
    private function insertPassage(array $doc): int
    {
        Db::execute(
            'INSERT INTO reading_passages (slug, kind, title, body, word_count) VALUES (?,?,?,?,?)',
            [
                $doc['slug'], $doc['kind'], $doc['title'], $doc['body'],
                Text::wordCount(preg_replace(self::MARKER, ' ', $doc['body']) ?? $doc['body']),
            ]
        );

        return (int) Db::pdo()->lastInsertId();
    }

    /**
     * The insertion task's lettered parts are shared by every gap, so they hang off the
     * passage with a null item_id. A part no item claims is a decoy, which is the whole
     * point of offering seven for five holes.
     *
     * @param  array<string,mixed> $doc
     * @return array<string,int>   label => option id
     */
    private function insertBank(int $passageId, array $doc): array
    {
        if ($doc['kind'] !== 'insert') {
            return [];
        }

        $bank = [];
        foreach (array_values($doc['bank']) as $sort => $part) {
            Db::execute(
                'INSERT INTO reading_options (passage_id, item_id, label, text, sort) VALUES (?, NULL, ?, ?, ?)',
                [$passageId, $part['label'], $part['text'], $sort]
            );
            $bank[$part['label']] = (int) Db::pdo()->lastInsertId();
        }

        return $bank;
    }

    /** @param array<string,mixed> $item */
    private function insertItem(int $passageId, string $kind, array $item): int
    {
        Db::execute(
            'INSERT INTO reading_items (passage_id, position, points, prompt) VALUES (?,?,?,?)',
            [$passageId, $item['position'], self::POINTS[$kind], $item['prompt'] ?? null]
        );

        return (int) Db::pdo()->lastInsertId();
    }

    /**
     * @param array<string,mixed> $item
     * @param array<string,int>   $bank
     */
    private function attachAnswer(int $passageId, int $itemId, string $kind, array $item, array $bank): void
    {
        if ($kind === 'insert') {
            $this->setCorrect($itemId, $bank[$item['correct_label']]);
            return;
        }

        $correct = null;
        foreach (array_values($item['options']) as $sort => $option) {
            Db::execute(
                'INSERT INTO reading_options (passage_id, item_id, label, text, sort) VALUES (?,?,?,?,?)',
                [$passageId, $itemId, $option['label'], $option['text'], $sort]
            );
            if (!empty($option['correct'])) {
                $correct = (int) Db::pdo()->lastInsertId();
            }
        }

        $this->setCorrect($itemId, $correct);
    }

    private function setCorrect(int $itemId, ?int $optionId): void
    {
        Db::execute('UPDATE reading_items SET correct_option_id = ? WHERE id = ?', [$optionId, $itemId]);
    }

    // ---- validation --------------------------------------------------------

    /** @param array<string,mixed> $doc */
    private function validate(array $doc): void
    {
        $kind = $doc['kind'] ?? '';
        if (!isset(self::POINTS[$kind])) {
            throw new InvalidPassage("Unknown task kind '{$kind}'.");
        }
        if (empty($doc['items'])) {
            throw new InvalidPassage('A passage carries no items.');
        }

        $this->validateMarkers($doc, $kind);

        if ($kind === 'insert') {
            $this->validateBank($doc);
            return;
        }

        $this->validateOptions($doc);
    }

    /** @param array<string,mixed> $doc */
    private function validateMarkers(array $doc, string $kind): void
    {
        preg_match_all(self::MARKER, (string) $doc['body'], $m);
        $markers = array_map('intval', $m[1]);

        if (!in_array($kind, self::GAP_KINDS, true)) {
            if ($markers !== []) {
                throw new InvalidPassage("A {$kind} passage must not contain gap markers.");
            }
            return;
        }

        $positions = array_map(static fn(array $i): int => (int) $i['position'], $doc['items']);
        sort($markers);
        sort($positions);

        if ($markers !== $positions) {
            throw new InvalidPassage(
                'Gap markers ' . json_encode($markers) . ' do not match item positions ' . json_encode($positions) . '.'
            );
        }
    }

    /** @param array<string,mixed> $doc */
    private function validateBank(array $doc): void
    {
        $labels = array_column($doc['bank'] ?? [], 'label');

        if (count($labels) <= count($doc['items'])) {
            throw new InvalidPassage('An insertion bank must offer more parts than there are gaps.');
        }
        if (count($labels) !== count(array_unique($labels))) {
            throw new InvalidPassage('An insertion bank repeats a label.');
        }

        $claimed = [];
        foreach ($doc['items'] as $item) {
            $label = $item['correct_label'] ?? null;
            if (!in_array($label, $labels, true)) {
                throw new InvalidPassage("Gap {$item['position']} names part '{$label}', which the bank does not offer.");
            }
            if (isset($claimed[$label])) {
                throw new InvalidPassage("Part '{$label}' fills more than one gap.");
            }
            $claimed[$label] = true;
        }
    }

    /** @param array<string,mixed> $doc */
    private function validateOptions(array $doc): void
    {
        foreach ($doc['items'] as $item) {
            $options = $item['options'] ?? [];
            if (count($options) < 3) {
                throw new InvalidPassage("Item {$item['position']} offers fewer than three options.");
            }

            $correct = array_filter($options, static fn(array $o): bool => !empty($o['correct']));
            if (count($correct) !== 1) {
                throw new InvalidPassage(
                    "Item {$item['position']} marks " . count($correct) . ' options correct, expected exactly one.'
                );
            }
        }
    }
}
