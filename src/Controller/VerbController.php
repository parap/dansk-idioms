<?php declare(strict_types=1);

namespace Dansk\Controller;

use Dansk\Domain\Reading\ReadingRepository;
use Dansk\Domain\Reading\ReadingSessionService;
use Dansk\Http\Response;
use Dansk\Support\Auth;
use RuntimeException;

/**
 * Verb drills: sets of questions about the forms of frequent Danish verbs.
 *
 * Only the list and the start are their own endpoints; answering, handing in and the
 * review go through the session API under /reading/sessions, as a film does.
 */
final class VerbController
{
    public function __construct(
        private ReadingSessionService $reading = new ReadingSessionService(),
        private ReadingRepository $sets = new ReadingRepository(),
    ) {}

    public function list(): void
    {
        Response::json(['sets' => $this->sets->publishedVerbSets()]);
    }

    public function start(array $body): void
    {
        $slug = isset($body['slug']) && is_string($body['slug']) ? $body['slug'] : '';

        try {
            $session = $this->reading->startVerbs(Auth::userId(), Auth::anonKey(), $slug);
            Response::json($this->reading->session($session['session_id']));
        } catch (RuntimeException $e) {
            Response::error('not_found', $e->getMessage(), 404);
        }
    }
}
