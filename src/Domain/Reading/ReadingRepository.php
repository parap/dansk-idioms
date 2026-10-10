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
    private const POINTS = ['mc' => 2, 'insert' => 2, 'cloze' => 1, 'quiz' => 1, 'video' => 1, 'verbs' => 1];

    /** Task types that are questions alone, with nothing to read before answering. */
    private const TEXTLESS_KINDS = ['quiz', 'video', 'verbs'];

    /** Only the knowledge paper divides its questions into the exam's blocks. */
    private const BLOCK_KINDS = ['quiz'];

    /** The blocks a knowledge paper divides its questions into. */
    private const BLOCKS = ['laeremateriale', 'aktuelle', 'vaerdier'];

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

    /**
     * The exam papers on offer, oldest first, each with what a learner needs to choose
     * between them: how long it is, what it takes to pass, and whether it still carries a
     * current-affairs block to leave out.
     *
     * @return list<array<string,mixed>>
     */
    public function publishedPapers(): array
    {
        return Db::fetchAll(
            "SELECT p.slug, p.title, p.pass_points AS pass, p.vaerdier_min,
                    COUNT(i.id) AS questions,
                    SUM(i.section = 'aktuelle') AS aktuelle
               FROM reading_passages p
               JOIN reading_items i ON i.passage_id = p.id AND i.is_active = 1 AND i.is_flagged = 0
              WHERE p.is_published = 1 AND p.kind = 'quiz'
              GROUP BY p.id, p.slug, p.title, p.pass_points, p.vaerdier_min
              ORDER BY p.slug"
        );
    }

    /**
     * The films on offer, each with the number of questions a learner will be asked.
     *
     * @return list<array{slug:string,title:string,youtube:string,questions:int}>
     */
    public function publishedVideos(): array
    {
        // By title in the Danish alphabet (æ, ø, å after z), not by id: the id order is
        // whatever order a database happened to import the files in.
        return $this->publishedSets('video', 'p.title COLLATE utf8mb4_danish_ci');
    }

    /**
     * The verb drills on offer, each with the number of questions a learner will be asked.
     *
     * @return list<array{slug:string,title:string,questions:int}>
     */
    public function publishedVerbSets(): array
    {
        return array_map(
            static fn(array $r): array => ['slug' => $r['slug'], 'title' => $r['title'], 'questions' => $r['questions']],
            $this->publishedSets('verbs')
        );
    }

    /**
     * The published passages of one textless kind, each with its servable question count.
     *
     * @return list<array{slug:string,title:string,youtube:?string,questions:int}>
     */
    private function publishedSets(string $kind, string $orderBy = 'p.id'): array
    {
        $rows = Db::fetchAll(
            "SELECT p.slug, p.title, p.youtube_id AS youtube, COUNT(i.id) AS questions
               FROM reading_passages p
               JOIN reading_items i ON i.passage_id = p.id AND i.is_active = 1 AND i.is_flagged = 0
              WHERE p.is_published = 1 AND p.kind = ?
              GROUP BY p.id, p.slug, p.title, p.youtube_id
              ORDER BY $orderBy",
            [$kind]
        );

        return array_map(static fn(array $r): array => array_merge($r, ['questions' => (int) $r['questions']]), $rows);
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
        $body = $doc['body'] ?? null;

        Db::execute(
            'INSERT INTO reading_passages (slug, kind, title, body, youtube_id, word_count, pass_points, vaerdier_min)
             VALUES (?,?,?,?,?,?,?,?)',
            [
                $doc['slug'], $doc['kind'], $doc['title'], $body, $doc['youtube'] ?? null,
                $body === null ? 0 : Text::wordCount(preg_replace(self::MARKER, ' ', $body) ?? $body),
                $doc['pass'] ?? null,
                $doc['vaerdier_min'] ?? null,
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
            'INSERT INTO reading_items (passage_id, position, section, points, prompt, note) VALUES (?,?,?,?,?,?)',
            [$passageId, $item['position'], $item['section'] ?? null, self::POINTS[$kind], $item['prompt'] ?? null,
             $item['note'] ?? null]
        );

        return (int) Db::pdo()->lastInsertId();
    }

    /**
     * Writes a loaded document's notes onto the passage already stored under its slug. A
     * note is matched to its question by position and wording, so a reworded question
     * keeps what it had; nothing graded is touched, which is why a set already sat can
     * take this where a replacement would be refused.
     *
     * @param  array<string,mixed> $doc
     * @return int how many questions' notes changed
     */
    public function refreshNotes(array $doc): int
    {
        $passageId = Db::fetchValue('SELECT id FROM reading_passages WHERE slug = ?', [$doc['slug']]);
        if ($passageId === false || $passageId === null) {
            return 0;
        }

        $changed = 0;
        foreach ($doc['items'] as $item) {
            $changed += Db::execute(
                'UPDATE reading_items SET note = ?
                  WHERE passage_id = ? AND position = ? AND prompt <=> ? AND NOT (note <=> ?)',
                [$item['note'] ?? null, (int) $passageId, $item['position'], $item['prompt'] ?? null, $item['note'] ?? null]
            );
        }
        return $changed;
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

        if (in_array($kind, self::BLOCK_KINDS, true)) {
            $this->validateBlocks($doc);
        }
        if ($kind === 'video' && !preg_match('/^[A-Za-z0-9_-]{11}$/', (string) ($doc['youtube'] ?? ''))) {
            throw new InvalidPassage('A video task names no YouTube film.');
        }

        $this->validateOptions($doc, $kind);
    }

    /**
     * A knowledge paper is graded against its own pass mark, so a mark the paper cannot
     * reach -- or a values requirement for a block it never asked -- is refused here
     * rather than discovered by the first learner who answers everything correctly and
     * is still told they failed.
     *
     * @param array<string,mixed> $doc
     */
    private function validateBlocks(array $doc): void
    {
        $blocks = [];
        foreach ($doc['items'] as $item) {
            $block = $item['section'] ?? null;
            if (!in_array($block, self::BLOCKS, true)) {
                throw new InvalidPassage(
                    "Item {$item['position']} names block '" . ($block ?? 'none') . "', which is not one of "
                    . implode(', ', self::BLOCKS) . '.'
                );
            }
            $blocks[$block] = ($blocks[$block] ?? 0) + 1;
        }

        $pass = $doc['pass'] ?? null;
        if ($pass !== null && $pass > count($doc['items'])) {
            throw new InvalidPassage(
                "The pass mark is {$pass} of " . count($doc['items']) . ' questions, which nobody can reach.'
            );
        }

        $values = $doc['vaerdier_min'] ?? null;
        if ($values !== null && $values > ($blocks['vaerdier'] ?? 0)) {
            throw new InvalidPassage(
                "The pass mark requires {$values} correct in the vaerdier block, which has "
                . ($blocks['vaerdier'] ?? 0) . ' question(s).'
            );
        }
    }

    /** @param array<string,mixed> $doc */
    private function validateMarkers(array $doc, string $kind): void
    {
        preg_match_all(self::MARKER, (string) ($doc['body'] ?? ''), $m);
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
    private function validateOptions(array $doc, string $kind): void
    {
        $least = in_array($kind, self::BLOCK_KINDS, true) ? 2 : 3;

        foreach ($doc['items'] as $item) {
            $options = $item['options'] ?? [];
            if (count($options) < $least) {
                $word = $least === 2 ? 'two' : 'three';
                throw new InvalidPassage("Item {$item['position']} offers fewer than {$word} options.");
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
