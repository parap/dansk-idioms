<?php declare(strict_types=1);

namespace Dansk\Domain;

use Dansk\Support\Db;

/**
 * Round selection, question generation and grading.
 *
 * Questions are generated and stored server-side BEFORE being served, and
 * correct_index never appears in a question response. Grading reads the stored row,
 * never the request body, so the client cannot influence its own score.
 */
final class QuizService
{
    public function __construct(private DistractorService $distractors = new DistractorService()) {}

    public function start(?int $userId, ?string $anonKey, string $lang = 'ru', int $length = 10): array
    {
        $idioms = $this->selectIdioms($userId, $lang, $length);
        if ($idioms === []) {
            throw new \RuntimeException('No published idioms are available yet.');
        }
        // Whether each individual question can be built is decided per question by
        // the distractor pool; a short round is perfectly legitimate.

        $publicId = $this->ulid();
        Db::execute(
            'INSERT INTO quiz_sessions (public_id, user_id, anon_key, lang_code, question_count)
             VALUES (?,?,?,?,?)',
            [$publicId, $userId, $anonKey, $lang, count($idioms)]
        );
        $sessionId = (int) Db::pdo()->lastInsertId();

        $idiomIds = array_map(static fn(array $i): int => (int) $i['idiom_id'], $idioms);
        $position = 0;
        $built    = 0;

        foreach ($idioms as $correct) {
            $distractors = $this->distractors->pick($correct, $idiomIds, $lang);
            if (count($distractors) < 3) {
                continue;   // no defensible question for this idiom; skip it
            }

            $options = [['tr_id' => (int) $correct['id'], 'text' => $correct['text']]];
            foreach ($distractors as $d) {
                $options[] = ['tr_id' => (int) $d['id'], 'text' => $d['text']];
            }
            shuffle($options);

            $correctIndex = 0;
            foreach ($options as $i => $o) {
                if ($o['tr_id'] === (int) $correct['id']) {
                    $correctIndex = $i;
                    break;
                }
            }

            $position++;
            $built++;
            Db::execute(
                'INSERT INTO quiz_questions
                    (session_id, position, idiom_id, correct_tr_id, options, correct_index)
                 VALUES (?,?,?,?,?,?)',
                [
                    $sessionId, $position, (int) $correct['idiom_id'], (int) $correct['id'],
                    json_encode(array_map(
                        static fn(array $o, int $i): array => ['i' => $i] + $o,
                        $options, array_keys($options)
                    ), JSON_UNESCAPED_UNICODE),
                    $correctIndex,
                ]
            );
        }

        if ($built === 0) {
            throw new \RuntimeException('Could not build any question.');
        }
        Db::execute('UPDATE quiz_sessions SET question_count = ? WHERE id = ?', [$built, $sessionId]);

        return ['public_id' => $publicId, 'question_count' => $built];
    }

    /** The prompt and options only -- never correct_index. */
    public function question(string $publicId, int $position): ?array
    {
        $row = Db::fetchOne(
            'SELECT q.position, q.options, q.chosen_index, q.is_correct,
                    i.term, i.term_note, i.kind, i.register,
                    s.question_count, s.correct_count, s.status
             FROM quiz_questions q
             JOIN quiz_sessions s ON s.id = q.session_id
             JOIN idioms i ON i.id = q.idiom_id
             WHERE s.public_id = ? AND q.position = ?',
            [$publicId, $position]
        );
        if ($row === null) {
            return null;
        }

        $options = json_decode((string) $row['options'], true) ?: [];

        return [
            'position'  => (int) $row['position'],
            'total'     => (int) $row['question_count'],
            'answered'  => $row['chosen_index'] !== null,
            'prompt'    => [
                'term'      => $row['term'],
                'term_note' => $row['term_note'],
                'kind'      => $row['kind'],
                'register'  => $row['register'],
            ],
            'options'   => array_map(
                static fn(array $o): array => ['index' => $o['i'], 'text' => $o['text']],
                $options
            ),
            'score'     => ['correct' => (int) $row['correct_count']],
        ];
    }

    public function answer(string $publicId, int $position, int $chosenIndex, ?int $responseMs): array
    {
        $row = Db::fetchOne(
            'SELECT q.id, q.session_id, q.correct_index, q.chosen_index, q.idiom_id, q.correct_tr_id,
                    s.id AS sid, s.question_count, s.correct_count, s.user_id
             FROM quiz_questions q JOIN quiz_sessions s ON s.id = q.session_id
             WHERE s.public_id = ? AND q.position = ?',
            [$publicId, $position]
        );
        if ($row === null) {
            throw new \RuntimeException('No such question.');
        }
        if ($row['chosen_index'] !== null) {
            throw new \RuntimeException('Already answered.');
        }

        $isCorrect = ((int) $row['correct_index']) === $chosenIndex;
        // Clamp: response_ms is client-supplied and would otherwise poison the stats.
        $ms = $responseMs === null ? null : max(0, min(300_000, $responseMs));

        Db::execute(
            'UPDATE quiz_questions SET chosen_index = ?, is_correct = ?, response_ms = ?, answered_at = NOW()
             WHERE id = ?',
            [$chosenIndex, $isCorrect ? 1 : 0, $ms, (int) $row['id']]
        );
        Db::execute(
            'UPDATE quiz_sessions SET answered_count = answered_count + 1,
                    correct_count = correct_count + ? WHERE id = ?',
            [$isCorrect ? 1 : 0, (int) $row['sid']]
        );
        Db::execute(
            'UPDATE idioms SET times_asked = times_asked + 1, times_correct = times_correct + ?
             WHERE id = ?',
            [$isCorrect ? 1 : 0, (int) $row['idiom_id']]
        );

        if ($row['user_id'] !== null) {
            $this->recordProgress((int) $row['user_id'], (int) $row['idiom_id'], $isCorrect);
        }

        $detail = Db::fetchOne(
            'SELECT i.term, e.body AS explanation
             FROM idioms i LEFT JOIN idiom_explanations e ON e.idiom_id = i.id AND e.lang_code = \'ru\'
             WHERE i.id = ?',
            [(int) $row['idiom_id']]
        );
        $correctText = Db::fetchValue('SELECT text FROM idiom_translations WHERE id = ?', [(int) $row['correct_tr_id']]);

        $answered = ((int) $row['question_count']) <= $position;

        return [
            'is_correct'    => $isCorrect,
            'correct_index' => (int) $row['correct_index'],
            'correct_text'  => $correctText,
            'explanation'   => $detail['explanation'] ?? null,
            'score'         => [
                'correct'  => ((int) $row['correct_count']) + ($isCorrect ? 1 : 0),
                'answered' => $position,
                'total'    => (int) $row['question_count'],
            ],
            'next_position' => $answered ? null : $position + 1,
        ];
    }

    public function result(string $publicId): ?array
    {
        $session = Db::fetchOne(
            'SELECT id, question_count, correct_count, started_at, status FROM quiz_sessions WHERE public_id = ?',
            [$publicId]
        );
        if ($session === null) {
            return null;
        }
        Db::execute(
            "UPDATE quiz_sessions SET status = 'finished', finished_at = NOW(),
                    duration_ms = TIMESTAMPDIFF(MICROSECOND, started_at, NOW()) / 1000
             WHERE id = ? AND status = 'active'",
            [(int) $session['id']]
        );

        $rows = Db::fetchAll(
            'SELECT q.position, q.is_correct, i.term, t.text AS correct_text
             FROM quiz_questions q
             JOIN idioms i ON i.id = q.idiom_id
             JOIN idiom_translations t ON t.id = q.correct_tr_id
             WHERE q.session_id = ? ORDER BY q.position',
            [(int) $session['id']]
        );

        return [
            'correct' => (int) $session['correct_count'],
            'total'   => (int) $session['question_count'],
            'questions' => array_map(static fn(array $r): array => [
                'position'     => (int) $r['position'],
                'is_correct'   => $r['is_correct'] === null ? null : (bool) $r['is_correct'],
                'term'         => $r['term'],
                'correct_text' => $r['correct_text'],
            ], $rows),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function selectIdioms(?int $userId, string $lang, int $length): array
    {
        // Signed-in users see what is due first (SM-2), then unseen, then anything.
        if ($userId !== null) {
            $rows = Db::fetchAll(
                "SELECT t.id, t.idiom_id, t.text, t.word_count, t.char_count, t.shape,
                        i.register, i.kind
                 FROM idiom_translations t
                 JOIN idioms i ON i.id = t.idiom_id AND i.is_published = 1
                 LEFT JOIN user_idiom_progress p ON p.idiom_id = i.id AND p.user_id = ?
                 WHERE t.lang_code = ? AND t.is_primary = 1 AND t.quiz_usable = 1
                 ORDER BY
                    CASE WHEN p.due_at IS NOT NULL AND p.due_at <= NOW() THEN 0
                         WHEN p.user_id IS NULL THEN 1 ELSE 2 END,
                    RAND()
                 LIMIT " . $this->limit($length),
                [$userId, $lang]
            );
            if ($rows !== []) {
                return $rows;
            }
        }

        return Db::fetchAll(
            "SELECT t.id, t.idiom_id, t.text, t.word_count, t.char_count, t.shape,
                    i.register, i.kind
             FROM idiom_translations t
             JOIN idioms i ON i.id = t.idiom_id AND i.is_published = 1
             WHERE t.lang_code = ? AND t.is_primary = 1 AND t.quiz_usable = 1
             ORDER BY RAND() LIMIT " . $this->limit($length),
            [$lang]
        );
    }

    /**
     * LIMIT cannot take a bound parameter here: with ATTR_EMULATE_PREPARES = false
     * PDO sends values as strings and MySQL rejects LIMIT '10'. Validated and
     * interpolated instead, which is safe precisely because it is an int.
     */
    private function limit(int $n): int
    {
        return max(1, min(50, $n));
    }

    /** SM-2, lightly simplified: no per-answer quality grade, just right or wrong. */
    private function recordProgress(int $userId, int $idiomId, bool $isCorrect): void
    {
        $p = Db::fetchOne(
            'SELECT ease, interval_days, repetitions, lapses FROM user_idiom_progress
             WHERE user_id = ? AND idiom_id = ?',
            [$userId, $idiomId]
        );

        $ease   = (float) ($p['ease'] ?? 2.50);
        $reps   = (int) ($p['repetitions'] ?? 0);
        $lapses = (int) ($p['lapses'] ?? 0);

        if ($isCorrect) {
            $reps++;
            $interval = match ($reps) { 1 => 1, 2 => 6, default => (int) round(((int) ($p['interval_days'] ?? 1)) * $ease) };
            $ease = min(3.0, $ease + 0.1);
        } else {
            $reps = 0;
            $lapses++;
            $interval = 1;
            $ease = max(1.3, $ease - 0.2);
        }

        Db::execute(
            'INSERT INTO user_idiom_progress
                (user_id, idiom_id, ease, interval_days, repetitions, lapses, due_at, last_result, last_answered_at)
             VALUES (?,?,?,?,?,?, DATE_ADD(NOW(), INTERVAL ? DAY), ?, NOW())
             ON DUPLICATE KEY UPDATE
                ease = VALUES(ease), interval_days = VALUES(interval_days),
                repetitions = VALUES(repetitions), lapses = VALUES(lapses),
                due_at = VALUES(due_at), last_result = VALUES(last_result),
                last_answered_at = VALUES(last_answered_at)',
            [$userId, $idiomId, $ease, $interval, $reps, $lapses, $interval, $isCorrect ? 1 : 0]
        );
    }

    /** Crockford base32 ULID -- 26 chars, so bot callback_data stays inside 64 bytes. */
    private function ulid(): string
    {
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $time     = (int) (microtime(true) * 1000);
        $out      = '';
        for ($i = 9; $i >= 0; $i--) {
            $out = $alphabet[$time % 32] . $out;
            $time = intdiv($time, 32);
        }
        for ($i = 0; $i < 16; $i++) {
            $out .= $alphabet[random_int(0, 31)];
        }
        return $out;
    }
}
