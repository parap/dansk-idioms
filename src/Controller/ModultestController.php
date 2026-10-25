<?php declare(strict_types=1);

namespace Dansk\Controller;

use Dansk\Domain\Reading\ReadingRepository;
use Dansk\Domain\Reading\ReadingSessionService;
use Dansk\Http\Response;
use Dansk\Support\Auth;
use RuntimeException;

/**
 * Reading papers sat as a whole: the Modultest DU3.4 variants, and past Prøve i Dansk 2
 * papers (pd2-…), each page listing its own.
 *
 * Only the list and the start are their own endpoints; answering, handing in and the
 * review go through the session API under /reading/sessions.
 */
final class ModultestController
{
    public function __construct(
        private ReadingSessionService $reading = new ReadingSessionService(),
        private ReadingRepository $papers = new ReadingRepository(),
    ) {}

    public function list(): void
    {
        Response::json(['papers' => $this->papers->publishedModultests()]);
    }

    public function start(array $body): void
    {
        $paper = isset($body['paper']) && is_string($body['paper']) ? $body['paper'] : '';

        try {
            $session = $this->reading->startModultest(Auth::userId(), Auth::anonKey(), $paper);
            Response::json($this->reading->session($session['session_id']));
        } catch (RuntimeException $e) {
            Response::error('not_found', $e->getMessage(), 404);
        }
    }
}
