<?php declare(strict_types=1);

namespace Dansk\Domain;

use Dansk\Support\Db;
use Dansk\Support\Ulid;

/**
 * Round selection, question generation and grading.
 *
 * Questions are generated and stored server-side BEFORE being served, and
 * correct_index never appears in a question response. Grading reads the stored row,
 * never the request body, so the client cannot influence its own score.
 */
final class QuizService
{
    public const FORWARD = 'da_to_tr';
    public const REVERSE = 'tr_to_da';

    public function __construct(private DistractorService $distractors = new DistractorService()) {}

    public function start(
        ?int $userId,
        ?string $anonKey,
        string $lang = 'ru',
        int $length = 10,
        string $direction = self::FORWARD
    ): array {
        $direction = $direction === self::REVERSE ? self::REVERSE : self::FORWARD;

        $idioms = $this->selectIdioms($userId, $lang, $length);
        if ($idioms === []) {
            throw new \RuntimeException('No published idioms are available yet.');
        }
        // Whether each individual question can be built is decided per question by
        // the distractor pool; a short round is perfectly legitimate.

        $publicId = Ulid::generate();
        Db::execute(
            'INSERT INTO quiz_sessions (public_id, user_id, anon_key, lang_code, direction, question_count)
             VALUES (?,?,?,?,?,?)',
            [$publicId, $userId, $anonKey, $lang, $direction, count($idioms)]
        );
        $sessionId = (int) Db::pdo()->lastInsertId();

        $idiomIds = array_map(static fn(array $i): int => (int) $i['idiom_id'], $idioms);
        $built    = 0;

        foreach ($idioms as $correct) {
            $options = $direction === self::REVERSE
                ? $this->reverseOptions($correct, $idiomIds)
                : $this->forwardOptions($correct, $idiomIds, $lang);

            if ($options === []) {
                continue;   // no defensible question for this idiom; skip it
            }

            shuffle($options);
            $correctIndex = 0;
            foreach ($options as $i => $option) {
                if ($option['correct']) {
                    $correctIndex = $i;
                    break;
                }
            }

            $built++;
            Db::execute(
                'INSERT INTO quiz_questions
                    (session_id, position, idiom_id, correct_tr_id, options, correct_index)
                 VALUES (?,?,?,?,?,?)',
                [
                    $sessionId, $built, (int) $correct['idiom_id'], (int) $correct['id'],
                    json_encode(array_map(
                        static fn(array $o, int $i): array => [
                            'i' => $i, 'ref' => $o['ref'], 'text' => $o['text'],
                        ] + (isset($o['decoy']) ? ['decoy' => $o['decoy']] : []),
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

        return ['public_id' => $publicId, 'question_count' => $built, 'direction' => $direction];
    }

    /**
     * Danish prompt, Russian options. `ref` is the translation row behind each option.
     *
     * @return list<array{ref:int,text:string,correct:bool}>
     */
    private function forwardOptions(array $correct, array $idiomIds, string $lang): array
    {
        $distractors = $this->distractors->pick($correct, $idiomIds, $lang);
        if (count($distractors) < 3) {
            return [];
        }

        $options = [['ref' => (int) $correct['id'], 'text' => (string) $correct['text'], 'correct' => true]];
        foreach ($distractors as $d) {
            $options[] = isset($d['decoy_id'])
                ? ['ref' => null, 'decoy' => (int) $d['decoy_id'], 'text' => (string) $d['text'], 'correct' => false]
                : ['ref' => (int) $d['id'], 'text' => (string) $d['text'], 'correct' => false];
        }
        return $options;
    }

    /**
     * Russian prompt, Danish options. `ref` is the idiom behind each option, since the
     * options are terms rather than translations.
     *
     * @return list<array{ref:int,text:string,correct:bool}>
     */
    private function reverseOptions(array $correct, array $idiomIds): array
    {
        $distractors = $this->distractors->pickTerms($correct, $idiomIds);
        if (count($distractors) < 3) {
            return [];
        }

        $options = [['ref' => (int) $correct['idiom_id'], 'text' => (string) $correct['term'], 'correct' => true]];
        foreach ($distractors as $d) {
            $options[] = ['ref' => (int) $d['idiom_id'], 'text' => (string) $d['term'], 'correct' => false];
        }
        return $options;
    }

    /** The prompt and options only -- never correct_index. */
    public function question(string $publicId, int $position): ?array
    {
        $row = Db::fetchOne(
            'SELECT q.position, q.options, q.chosen_index,
                    i.term, i.term_note, i.kind, i.register,
                    t.text AS translation,
                    s.question_count, s.correct_count, s.direction
             FROM quiz_questions q
             JOIN quiz_sessions s ON s.id = q.session_id
             JOIN idioms i ON i.id = q.idiom_id
             JOIN idiom_translations t ON t.id = q.correct_tr_id
             WHERE s.public_id = ? AND q.position = ?',
            [$publicId, $position]
        );
        if ($row === null) {
            return null;
        }

        $reverse = $row['direction'] === self::REVERSE;
        $options = json_decode((string) $row['options'], true) ?: [];

        return [
            'position'  => (int) $row['position'],
            'total'     => (int) $row['question_count'],
            'answered'  => $row['chosen_index'] !== null,
            'direction' => $row['direction'],
            'prompt'    => [
                // In reverse the prompt is the meaning and the options are the idioms,
                // so the term must not appear anywhere in the payload -- it is the answer.
                'text'     => $reverse ? $row['translation'] : $row['term'],
                'note'     => $reverse ? null : $row['term_note'],
                'kind'     => $row['kind'],
                'register' => $row['register'],
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
                    s.id AS sid, s.question_count, s.correct_count, s.user_id, s.direction
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
        // What "the correct answer" reads as depends on which way round the question
        // was asked: the meaning going forward, the idiom going back.
        $correctText = $row['direction'] === self::REVERSE
            ? ($detail['term'] ?? null)
            : Db::fetchValue('SELECT text FROM idiom_translations WHERE id = ?', [(int) $row['correct_tr_id']]);

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

    /**
     * Records a report against a question; false when there is no such question.
     * An "also correct" report on a distractor counts towards blocking it.
     */
    public function report(string $publicId, int $position, string $reason): bool
    {
        $row = Db::fetchOne(
            'SELECT q.id, q.correct_tr_id, q.chosen_index, q.options, s.direction
             FROM quiz_questions q JOIN quiz_sessions s ON s.id = q.session_id
             WHERE s.public_id = ? AND q.position = ?',
            [$publicId, $position]
        );
        if ($row === null) {
            return false;
        }

        Db::execute('UPDATE quiz_questions SET reported = 1 WHERE id = ?', [(int) $row['id']]);

        if ($reason === 'also_correct' && $row['chosen_index'] !== null) {
            $options = json_decode((string) $row['options'], true) ?: [];
            $blocked = null;
            foreach ($options as $o) {
                if ((int) $o['i'] !== (int) $row['chosen_index']) {
                    continue;
                }
                if (isset($o['decoy'])) {
                    // report_count on the right of the IF is already incremented.
                    Db::execute(
                        'UPDATE idiom_decoys SET report_count = report_count + 1,
                                is_active = IF(report_count >= 2, 0, is_active) WHERE id = ?',
                        [(int) $o['decoy']]
                    );
                    break;
                }
                // Blocks are recorded between translations. Going forward the option
                // already is one; going back it is an idiom, so resolve its primary.
                $blocked = $row['direction'] === self::REVERSE
                    ? (int) Db::fetchValue(
                        'SELECT id FROM idiom_translations
                         WHERE idiom_id = ? AND is_primary = 1 LIMIT 1',
                        [(int) $o['ref']]
                    )
                    : (int) $o['ref'];
                break;
            }
            if ($blocked !== null && $blocked !== (int) $row['correct_tr_id']) {
                // Two independent reports activate the block automatically.
                Db::execute(
                    "INSERT INTO distractor_blocks (correct_tr_id, blocked_tr_id, reason, report_count, is_active)
                     VALUES (?,?, 'reported', 1, 0)
                     ON DUPLICATE KEY UPDATE
                        report_count = report_count + 1,
                        is_active = IF(report_count + 1 >= 2, 1, is_active)",
                    [(int) $row['correct_tr_id'], $blocked]
                );
            }
        }
        return true;
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
                        i.term, i.shape AS term_shape, i.register, i.kind
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
                    i.term, i.shape AS term_shape, i.register, i.kind
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

        $next = Sm2::next(
            (float) ($p['ease'] ?? Sm2::DEFAULT_EASE),
            (int) ($p['interval_days'] ?? 1),
            (int) ($p['repetitions'] ?? 0),
            (int) ($p['lapses'] ?? 0),
            $isCorrect,
        );

        Db::execute(
            'INSERT INTO user_idiom_progress
                (user_id, idiom_id, ease, interval_days, repetitions, lapses, due_at, last_result, last_answered_at)
             VALUES (?,?,?,?,?,?, DATE_ADD(NOW(), INTERVAL ? DAY), ?, NOW())
             ON DUPLICATE KEY UPDATE
                ease = VALUES(ease), interval_days = VALUES(interval_days),
                repetitions = VALUES(repetitions), lapses = VALUES(lapses),
                due_at = VALUES(due_at), last_result = VALUES(last_result),
                last_answered_at = VALUES(last_answered_at)',
            [$userId, $idiomId, $next['ease'], $next['interval_days'], $next['repetitions'],
             $next['lapses'], $next['interval_days'], $isCorrect ? 1 : 0]
        );
    }
}
