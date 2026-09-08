<?php declare(strict_types=1);

namespace Dansk\Controller;

use Dansk\Domain\Reading\ReadingSessionService;
use Dansk\Http\Response;
use Dansk\Support\Auth;
use RuntimeException;

final class ReadingController
{
    public function __construct(private ReadingSessionService $reading = new ReadingSessionService()) {}

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

    public function session(string $sid): void
    {
        try {
            Response::json($this->reading->session($sid));
        } catch (RuntimeException $e) {
            Response::error('not_found', $e->getMessage(), 404);
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
