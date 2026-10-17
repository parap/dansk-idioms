<?php declare(strict_types=1);

namespace Dansk\Controller;

use Dansk\Domain\QuizService;
use Dansk\Http\Response;
use Dansk\Support\Auth;
use Dansk\Support\Config;

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
        if (!$this->quiz->report($sid, $position, (string) ($body['reason'] ?? 'also_correct'))) {
            Response::error('not_found', 'No such question.', 404);
            return;
        }
        Response::json(['ok' => true]);
    }
}
