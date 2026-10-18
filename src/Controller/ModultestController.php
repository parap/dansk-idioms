<?php declare(strict_types=1);

namespace Dansk\Controller;

use Dansk\Domain\Reading\ReadingRepository;
use Dansk\Domain\Reading\ReadingSessionService;
use Dansk\Http\Response;
use Dansk\Support\Auth;
use RuntimeException;

/**
 * Modultest DU3.4 reading papers: the four tasks of a variant sat together.
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
