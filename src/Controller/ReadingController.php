<?php declare(strict_types=1);

namespace Dansk\Controller;

use Dansk\Domain\Reading\ReadingReportRepository;
use Dansk\Domain\Reading\ReadingSessionService;
use Dansk\Http\Response;
use Dansk\Support\Auth;
use RuntimeException;

final class ReadingController
{
    public function __construct(
        private ReadingSessionService $reading = new ReadingSessionService(),
        private ReadingReportRepository $reports = new ReadingReportRepository(),
    ) {}

    public function start(array $body): void
    {
        $mode = ($body['mode'] ?? ReadingSessionService::DRILL) === ReadingSessionService::EXAM
            ? ReadingSessionService::EXAM
            : ReadingSessionService::DRILL;

        $requested = $body['kind'] ?? null;
        $kind      = in_array($requested, ['mc', 'insert', 'cloze'], true) ? $requested : null;

        try {
            $session = $this->reading->start(Auth::userId(), Auth::anonKey(), $mode, $kind);
            Response::json($this->reading->session($session['session_id']));
        } catch (RuntimeException $e) {
            Response::error('cannot_build_round', $e->getMessage(), 409);
        }
    }

    /**
     * What this learner has already sat. Identity comes from the session or the cookie,
     * never from the request body: a history endpoint that took an identifier would hand
     * anyone else's sittings to whoever asked.
     */
    public function history(): void
    {
        Response::json([
            'sessions' => $this->reading->history(Auth::userId(), Auth::anonKey()),
            // Both counts ride along with the history, because both start screens ask
            // for the history anyway and neither needs a second round trip to find out
            // whether it has anything to offer.
            'mistakes' => [
                'quiz'    => $this->reading->mistakesWaiting(Auth::userId(), Auth::anonKey(), ['quiz']),
                'reading' => $this->reading->mistakesWaiting(
                    Auth::userId(), Auth::anonKey(), ['mc', 'insert', 'cloze']
                ),
            ],
        ]);
    }

    /** A round of the questions this learner last got wrong or left blank. */
    public function mistakes(array $body): void
    {
        $kinds = ($body['kind'] ?? null) === 'quiz' ? ['quiz'] : ['mc', 'insert', 'cloze'];

        try {
            $session = $this->reading->startMistakes(Auth::userId(), Auth::anonKey(), $kinds);
            Response::json($this->reading->session($session['session_id']));
        } catch (RuntimeException $e) {
            Response::error('nothing_to_review', $e->getMessage(), 409);
        }
    }

    public function session(string $sid): void
    {
        try {
            Response::json($this->reading->session($sid));
        } catch (RuntimeException $e) {
            Response::error('not_found', $e->getMessage(), 404);
        }
    }

    public function report(string $sid, int $position, array $body): void
    {
        $reasons = ['also_correct', 'no_correct', 'unclear', 'typo', 'other'];
        $reason  = in_array($body['reason'] ?? '', $reasons, true) ? $body['reason'] : 'also_correct';
        $note    = isset($body['note']) ? mb_substr(trim((string) $body['note']), 0, 500) : null;

        try {
            $this->reports->report($sid, $position, Auth::userId(), Auth::anonKey(), $reason, $note ?: null);
            Response::json(['ok' => true]);
        } catch (RuntimeException $e) {
            Response::error('cannot_report', $e->getMessage(), 409);
        }
    }

    public function submit(string $sid): void
    {
        try {
            Response::json($this->reading->submit($sid));
        } catch (RuntimeException $e) {
            Response::error('cannot_submit', $e->getMessage(), 409);
        }
    }

    public function result(string $sid): void
    {
        try {
            Response::json($this->reading->result($sid));
        } catch (RuntimeException $e) {
            Response::error('not_available', $e->getMessage(), 409);
        }
    }

    public function answer(string $sid, array $body): void
    {
        $position = (int) ($body['position'] ?? 0);
        $chosen   = (int) ($body['chosen_index'] ?? -1);
        $ms       = isset($body['response_ms']) ? (int) $body['response_ms'] : null;

        try {
            Response::json($this->reading->answer($sid, $position, $chosen, $ms));
        } catch (RuntimeException $e) {
            Response::error('cannot_answer', $e->getMessage(), 409);
        }
    }
}
