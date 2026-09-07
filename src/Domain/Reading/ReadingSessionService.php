<?php declare(strict_types=1);

namespace Dansk\Domain\Reading;

use Dansk\Domain\Sm2;
use Dansk\Support\Db;
use Dansk\Support\Ulid;
use RuntimeException;

/**
 * Runs a reading round.
 *
 * The whole paper is materialised into reading_session_items before any of it is served,
 * with the options in their presentation order and the correct index stored beside them.
 * A question response carries neither, and grading compares against the stored row, so
 * the browser cannot influence its own score.
 */
final class ReadingSessionService
{
    public const DRILL = 'drill';
    public const EXAM  = 'exam';

    /** Beyond this a client-supplied duration is noise, and it would poison the stats. */
    private const MAX_RESPONSE_MS = 300_000;

    /**
     * @return array{session_id: string, mode: string, points_max: int}
     */
    public function start(?int $userId, ?string $anonKey, string $mode = self::DRILL, ?string $kind = null): array
    {
        $passage = $this->pickPassage($kind);

        $publicId = Ulid::generate();
        Db::execute(
            'INSERT INTO reading_sessions (public_id, user_id, anon_key, mode) VALUES (?,?,?,?)',
            [$publicId, $userId, $anonKey, $mode]
        );
        $sessionId = (int) Db::pdo()->lastInsertId();

        $pointsMax = $this->materialise($sessionId, (int) $passage['id'], $passage['kind']);
        Db::execute('UPDATE reading_sessions SET points_max = ? WHERE id = ?', [$pointsMax, $sessionId]);

        return ['session_id' => $publicId, 'mode' => $mode, 'points_max' => $pointsMax];
    }

    /**
     * The paper as the learner sees it: the passage, and each item with its options in
     * presentation order. Nothing here identifies the answer.
     */
    public function session(string $publicId): array
    {
        $session = $this->loadSession($publicId);

        $rows = Db::fetchAll(
            'SELECT si.position, si.options, si.points, si.chosen_index, i.prompt
             FROM reading_session_items si
             JOIN reading_items i ON i.id = si.item_id
             WHERE si.session_id = ? ORDER BY si.position',
            [(int) $session['id']]
        );

        $passage = Db::fetchOne(
            'SELECT p.slug, p.kind, p.title, p.body FROM reading_passages p
             JOIN reading_session_items si ON si.passage_id = p.id
             WHERE si.session_id = ? LIMIT 1',
            [(int) $session['id']]
        );

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'position'     => (int) $row['position'],
                'prompt'       => $row['prompt'],
                'points'       => (int) $row['points'],
                'answered'     => $row['chosen_index'] !== null,
                'options'      => $this->publicOptions((string) $row['options']),
            ];
        }

        return [
            'session_id' => $publicId,
            'mode'       => $session['mode'],
            'status'     => $session['status'],
            'points_max' => (int) $session['points_max'],
            'passage'    => $passage,
            'items'      => $items,
        ];
    }

    /**
     * Grades one answer. In drill the learner is told immediately; in exam nothing comes
     * back but an acknowledgement, because feedback before submit is a different test.
     */
    public function answer(string $publicId, int $position, int $chosenIndex, ?int $responseMs = null): array
    {
        $session = $this->loadSession($publicId);
        if ($session['status'] !== 'active') {
            throw new RuntimeException('This round is already finished.');
        }

        $row = Db::fetchOne(
            'SELECT id, item_id, options, correct_index, points, chosen_index
             FROM reading_session_items WHERE session_id = ? AND position = ?',
            [(int) $session['id'], $position]
        );
        if ($row === null) {
            throw new RuntimeException('No such item.');
        }
        if ($row['chosen_index'] !== null) {
            throw new RuntimeException('Already answered.');
        }

        $isCorrect = ((int) $row['correct_index']) === $chosenIndex;
        $points    = $isCorrect ? (int) $row['points'] : 0;
        $ms        = $responseMs === null ? null : max(0, min(self::MAX_RESPONSE_MS, $responseMs));

        Db::execute(
            'UPDATE reading_session_items
                SET chosen_index = ?, is_correct = ?, points_scored = ?, response_ms = ?, answered_at = NOW()
              WHERE id = ?',
            [$chosenIndex, $isCorrect ? 1 : 0, $points, $ms, (int) $row['id']]
        );
        Db::execute(
            'UPDATE reading_sessions SET points_scored = points_scored + ? WHERE id = ?',
            [$points, (int) $session['id']]
        );
        Db::execute(
            'UPDATE reading_items SET times_asked = times_asked + 1, times_correct = times_correct + ?
              WHERE id = ?',
            [$isCorrect ? 1 : 0, (int) $row['item_id']]
        );

        if ($session['user_id'] !== null) {
            $this->recordProgress((int) $session['user_id'], (int) $row['item_id'], $isCorrect);
        }

        if ($session['mode'] === self::EXAM) {
            return ['recorded' => true];
        }

        return [
            'is_correct'    => $isCorrect,
            'correct_index' => (int) $row['correct_index'],
            'points_scored' => $points,
        ];
    }

    // ---- assembly ----------------------------------------------------------

    /** @return array<string,mixed> */
    private function pickPassage(?string $kind): array
    {
        $passage = Db::fetchOne(
            'SELECT p.id, p.kind FROM reading_passages p
             WHERE p.is_published = 1
               AND (? IS NULL OR p.kind = ?)
               AND EXISTS (SELECT 1 FROM reading_items i
                            WHERE i.passage_id = p.id AND i.is_active = 1 AND i.is_flagged = 0)
             ORDER BY RAND() LIMIT 1',
            [$kind, $kind]
        );

        if ($passage === null) {
            throw new RuntimeException('No published passage is available.');
        }

        return $passage;
    }

    /**
     * Freezes the paper. Option order is decided here, once per session, so an authored
     * order cannot be memorised across attempts and the stored index stays meaningful.
     */
    private function materialise(int $sessionId, int $passageId, string $kind): int
    {
        $items = Db::fetchAll(
            'SELECT id, position, points, correct_option_id FROM reading_items
             WHERE passage_id = ? AND is_active = 1 AND is_flagged = 0 ORDER BY position',
            [$passageId]
        );

        $bank      = $kind === 'insert' ? $this->bankOptions($passageId) : null;
        $pointsMax = 0;
        $position  = 0;

        foreach ($items as $item) {
            $options = $bank ?? $this->itemOptions((int) $item['id']);
            shuffle($options);

            $correctIndex = null;
            $payload      = [];
            foreach ($options as $i => $option) {
                $payload[] = ['i' => $i, 'ref' => (int) $option['id'], 'text' => $option['text']];
                if ((int) $option['id'] === (int) $item['correct_option_id']) {
                    $correctIndex = $i;
                }
            }
            if ($correctIndex === null) {
                throw new RuntimeException('Item ' . $item['position'] . ' has no correct option among its choices.');
            }

            $position++;
            Db::execute(
                'INSERT INTO reading_session_items
                    (session_id, position, item_id, passage_id, options, correct_index, points)
                 VALUES (?,?,?,?,?,?,?)',
                [
                    $sessionId, $position, (int) $item['id'], $passageId,
                    json_encode($payload, JSON_UNESCAPED_UNICODE), $correctIndex, (int) $item['points'],
                ]
            );
            $pointsMax += (int) $item['points'];
        }

        return $pointsMax;
    }

    /** @return list<array<string,mixed>> */
    private function itemOptions(int $itemId): array
    {
        return Db::fetchAll('SELECT id, text FROM reading_options WHERE item_id = ? ORDER BY sort', [$itemId]);
    }

    /** @return list<array<string,mixed>> */
    private function bankOptions(int $passageId): array
    {
        return Db::fetchAll(
            'SELECT id, text FROM reading_options WHERE passage_id = ? AND item_id IS NULL ORDER BY sort',
            [$passageId]
        );
    }

    // ---- reading -----------------------------------------------------------

    /** @return array<string,mixed> */
    private function loadSession(string $publicId): array
    {
        $session = Db::fetchOne(
            'SELECT id, user_id, mode, status, points_max, points_scored FROM reading_sessions WHERE public_id = ?',
            [$publicId]
        );
        if ($session === null) {
            throw new RuntimeException('No such round.');
        }

        return $session;
    }

    /**
     * Drops everything the client has no business seeing. The stored payload carries the
     * option's row id so a report can name it; the browser gets an index and a string.
     *
     * @return list<array{index: int, text: string}>
     */
    private function publicOptions(string $stored): array
    {
        $out = [];
        foreach (json_decode($stored, true) ?: [] as $option) {
            $out[] = ['index' => (int) $option['i'], 'text' => (string) $option['text']];
        }

        return $out;
    }

    private function recordProgress(int $userId, int $itemId, bool $isCorrect): void
    {
        $p = Db::fetchOne(
            'SELECT ease, interval_days, repetitions, lapses FROM user_reading_progress
             WHERE user_id = ? AND item_id = ?',
            [$userId, $itemId]
        );

        $next = Sm2::next(
            (float) ($p['ease'] ?? Sm2::DEFAULT_EASE),
            (int) ($p['interval_days'] ?? 1),
            (int) ($p['repetitions'] ?? 0),
            (int) ($p['lapses'] ?? 0),
            $isCorrect,
        );

        Db::execute(
            'INSERT INTO user_reading_progress
                (user_id, item_id, ease, interval_days, repetitions, lapses, due_at, last_result, last_answered_at)
             VALUES (?,?,?,?,?,?, DATE_ADD(NOW(), INTERVAL ? DAY), ?, NOW())
             ON DUPLICATE KEY UPDATE
                ease = VALUES(ease), interval_days = VALUES(interval_days),
                repetitions = VALUES(repetitions), lapses = VALUES(lapses),
                due_at = VALUES(due_at), last_result = VALUES(last_result),
                last_answered_at = VALUES(last_answered_at)',
            [
                $userId, $itemId, $next['ease'], $next['interval_days'], $next['repetitions'],
                $next['lapses'], $next['interval_days'], $isCorrect ? 1 : 0,
            ]
        );
    }
}
