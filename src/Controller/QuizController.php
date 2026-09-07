<?php declare(strict_types=1);

namespace Dansk\Controller;

use Dansk\Domain\QuizService;
use Dansk\Http\Response;
use Dansk\Support\Auth;
use Dansk\Support\Config;
use Dansk\Support\Db;

final class QuizController
{
    public function __construct(private QuizService $quiz = new QuizService()) {}

    public function start(array $body): void
    {
        $requested = (string) ($body['lang'] ?? 'ru');
        $lang      = in_array($requested, ['ru', 'en', 'da'], true) ? $requested : 'ru';
        $length = (int) ($body['length'] ?? Config::get('quiz.questions_per_round', 10));

        $direction = ($body['direction'] ?? QuizService::FORWARD) === QuizService::REVERSE
            ? QuizService::REVERSE
            : QuizService::FORWARD;

        try {
            $session  = $this->quiz->start(Auth::userId(), Auth::anonKey(), $lang, $length, $direction);
            $question = $this->quiz->question($session['public_id'], 1);
            Response::json([
                'session_id' => $session['public_id'],
                'direction'  => $session['direction'],
                'question'   => $question,
            ]);
        } catch (\RuntimeException $e) {
            Response::error('cannot_build_round', $e->getMessage(), 409);
        }
    }

    public function question(string $sid, int $position): void
    {
        $q = $this->quiz->question($sid, $position);
        $q === null
            ? Response::error('not_found', 'No such question.', 404)
            : Response::json($q);
    }

    public function answer(string $sid, array $body): void
    {
        $position = (int) ($body['position'] ?? 0);
        $chosen   = $body['chosen_index'] ?? null;
        if ($position < 1 || !is_int($chosen)) {
            Response::error('invalid', 'position and chosen_index are required.', 422);
            return;
        }
        try {
            Response::json($this->quiz->answer($sid, $position, $chosen, $body['response_ms'] ?? null));
        } catch (\RuntimeException $e) {
            Response::error('invalid', $e->getMessage(), 409);
        }
    }

    public function result(string $sid): void
    {
        $r = $this->quiz->result($sid);
        $r === null ? Response::error('not_found', 'No such session.', 404) : Response::json($r);
    }

    /** "This wrong answer was also correct" -- the quality feedback loop. */
    public function report(string $sid, int $position, array $body): void
    {
        $row = Db::fetchOne(
            'SELECT q.id, q.correct_tr_id, q.chosen_index, q.options, s.direction
             FROM quiz_questions q JOIN quiz_sessions s ON s.id = q.session_id
             WHERE s.public_id = ? AND q.position = ?',
            [$sid, $position]
        );
        if ($row === null) {
            Response::error('not_found', 'No such question.', 404);
            return;
        }

        Db::execute('UPDATE quiz_questions SET reported = 1 WHERE id = ?', [(int) $row['id']]);

        $reason = $body['reason'] ?? 'also_correct';
        if ($reason === 'also_correct' && $row['chosen_index'] !== null) {
            $options = json_decode((string) $row['options'], true) ?: [];
            $blocked = null;
            foreach ($options as $o) {
                if ((int) $o['i'] !== (int) $row['chosen_index']) {
                    continue;
                }
                // Blocks are recorded between translations. Going forward the option
                // already is one; going back it is an idiom, so resolve its primary.
                $blocked = $row['direction'] === QuizService::REVERSE
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
        Response::json(['ok' => true]);
    }
}
