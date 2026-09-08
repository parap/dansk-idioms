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

    /** Laeseforstaaelse 2 runs 65 minutes, whatever the browser's clock believes. */
    private const EXAM_SECONDS = 3900;

    /** A paper draws one text of each task type, in the order the exam presents them. */
    private const EXAM_KINDS = ['mc', 'insert', 'cloze'];

    public function __construct(private GradeScale $grades = new GradeScale()) {}

    /**
     * @return array{session_id: string, mode: string, points_max: int}
     */
    public function start(?int $userId, ?string $anonKey, string $mode = self::DRILL, ?string $kind = null): array
    {
        $passages = $mode === self::EXAM
            ? array_map(fn(string $k): array => $this->pickPassage($k), self::EXAM_KINDS)
            : [$this->pickPassage($kind)];

        $publicId = Ulid::generate();
        Db::execute(
            'INSERT INTO reading_sessions (public_id, user_id, anon_key, mode, duration_s, scale_id)
             VALUES (?,?,?,?,?,?)',
            [
                $publicId, $userId, $anonKey, $mode,
                $mode === self::EXAM ? self::EXAM_SECONDS : null,
                $this->grades->activeScaleId(),
            ]
        );
        $sessionId = (int) Db::pdo()->lastInsertId();

        $pointsMax = 0;
        $position  = 0;
        foreach ($passages as $passage) {
            [$pointsMax, $position] = $this->materialise(
                $sessionId, (int) $passage['id'], $passage['kind'], $pointsMax, $position
            );
        }
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
            'SELECT si.position, si.passage_id, si.options, si.points, si.chosen_index, i.prompt
             FROM reading_session_items si
             JOIN reading_items i ON i.id = si.item_id
             WHERE si.session_id = ? ORDER BY si.position',
            [(int) $session['id']]
        );

        $passages = Db::fetchAll(
            'SELECT p.id, p.slug, p.kind, p.title, p.body, MIN(si.position) AS first_position
             FROM reading_passages p
             JOIN reading_session_items si ON si.passage_id = p.id
             WHERE si.session_id = ?
             GROUP BY p.id, p.slug, p.kind, p.title, p.body
             ORDER BY first_position',
            [(int) $session['id']]
        );

        // Items name their passage by its place in the list. The row id stays server-side.
        $order = [];
        foreach ($passages as $i => $passage) {
            $order[(int) $passage['id']] = $i;
        }

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'position' => (int) $row['position'],
                'passage'  => $order[(int) $row['passage_id']] ?? 0,
                'prompt'   => $row['prompt'],
                'points'   => (int) $row['points'],
                'answered' => $row['chosen_index'] !== null,
                'options'  => $this->publicOptions((string) $row['options']),
            ];
        }

        return [
            'session_id'  => $publicId,
            'mode'        => $session['mode'],
            'status'      => $session['status'],
            'points_max'  => (int) $session['points_max'],
            'remaining_s' => $this->remaining($session),
            'passages'    => array_map(
                static fn(array $p): array => [
                    'slug'  => $p['slug'],
                    'kind'  => $p['kind'],
                    'title' => $p['title'],
                    'body'  => $p['body'],
                ],
                $passages
            ),
            'items'       => $items,
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
        // A real exam lets a candidate revise until they hand the paper in. A drill is
        // scored as it goes, so its first answer is the one that counts.
        if ($session['mode'] !== self::EXAM && $row['chosen_index'] !== null) {
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
        // Summed rather than incremented: an exam answer can change, and a running total
        // would count every revision.
        Db::execute(
            'UPDATE reading_sessions SET points_scored =
                (SELECT COALESCE(SUM(points_scored), 0) FROM reading_session_items WHERE session_id = ?)
              WHERE id = ?',
            [(int) $session['id'], (int) $session['id']]
        );
        if ($row['chosen_index'] === null) {
            Db::execute(
                'UPDATE reading_items SET times_asked = times_asked + 1, times_correct = times_correct + ?
                  WHERE id = ?',
                [$isCorrect ? 1 : 0, (int) $row['item_id']]
            );
        }

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

    /**
     * Hands the paper in. The elapsed time is measured by the database against the
     * timestamp it stamped at start, so a browser that stopped counting, slept, or lied
     * changes nothing.
     */
    public function submit(string $publicId): array
    {
        $session = $this->loadSession($publicId);
        if ($session['status'] !== 'active') {
            throw new RuntimeException('This round is already finished.');
        }

        $id       = (int) $session['id'];
        $elapsed  = (int) $session['elapsed_now'];
        $duration = $session['duration_s'] === null ? null : (int) $session['duration_s'];
        $isLate   = $duration !== null && $elapsed > $duration;

        $scored = (int) Db::fetchValue(
            'SELECT COALESCE(SUM(points_scored), 0) FROM reading_session_items WHERE session_id = ?',
            [$id]
        );
        $max      = (int) $session['points_max'];
        $karakter = $this->grades->karakter($scored, $max);

        Db::execute(
            "UPDATE reading_sessions
                SET status = 'submitted', submitted_at = NOW(), elapsed_s = ?, is_late = ?,
                    points_scored = ?, karakter = ?
              WHERE id = ?",
            [$elapsed, $isLate ? 1 : 0, $scored, $karakter, $id]
        );

        return [
            'points_scored' => $scored,
            'points_max'    => $max,
            'karakter'      => $karakter,
            'is_late'       => $isLate,
            'elapsed_s'     => $elapsed,
        ];
    }

    /**
     * The review, available only once the paper is in. Before that it would be the
     * answer key.
     */
    public function result(string $publicId): array
    {
        $session = $this->loadSession($publicId);
        if ($session['status'] !== 'submitted') {
            throw new RuntimeException('This round has not been submitted.');
        }

        $rows = Db::fetchAll(
            'SELECT si.position, si.options, si.correct_index, si.chosen_index, si.is_correct,
                    si.points, si.points_scored, i.prompt
             FROM reading_session_items si
             JOIN reading_items i ON i.id = si.item_id
             WHERE si.session_id = ? ORDER BY si.position',
            [(int) $session['id']]
        );

        return [
            'session_id'    => $publicId,
            'points_scored' => (int) $session['points_scored'],
            'points_max'    => (int) $session['points_max'],
            'karakter'      => $session['karakter'],
            'is_late'       => (bool) $session['is_late'],
            'elapsed_s'     => $session['elapsed_s'] === null ? null : (int) $session['elapsed_s'],
            'items'         => array_map(
                fn(array $r): array => [
                    'position'      => (int) $r['position'],
                    'prompt'        => $r['prompt'],
                    'options'       => $this->publicOptions((string) $r['options']),
                    'correct_index' => (int) $r['correct_index'],
                    'chosen_index'  => $r['chosen_index'] === null ? null : (int) $r['chosen_index'],
                    'is_correct'    => $r['is_correct'] === null ? null : (bool) $r['is_correct'],
                    'points'        => (int) $r['points'],
                    'points_scored' => (int) $r['points_scored'],
                ],
                $rows
            ),
        ];
    }

    /** Seconds left on an exam, counted by the server. Null when nothing is timed. */
    private function remaining(array $session): ?int
    {
        if ($session['duration_s'] === null) {
            return null;
        }

        return max(0, (int) $session['duration_s'] - (int) $session['elapsed_now']);
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
    private function materialise(
        int $sessionId,
        int $passageId,
        string $kind,
        int $pointsMax = 0,
        int $position = 0,
    ): array {
        $items = Db::fetchAll(
            'SELECT id, position, points, correct_option_id FROM reading_items
             WHERE passage_id = ? AND is_active = 1 AND is_flagged = 0 ORDER BY position',
            [$passageId]
        );

        $bank = $kind === 'insert' ? $this->bankOptions($passageId) : null;

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

        return [$pointsMax, $position];
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
            'SELECT id, user_id, mode, status, points_max, points_scored, karakter, duration_s, is_late,
                    elapsed_s, TIMESTAMPDIFF(SECOND, started_at, NOW()) AS elapsed_now
             FROM reading_sessions WHERE public_id = ?',
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
