<?php declare(strict_types=1);

namespace Dansk\Controller;

use Dansk\Domain\Reading\ReadingRepository;
use Dansk\Domain\Reading\ReadingSessionService;
use Dansk\Http\Response;
use Dansk\Support\Auth;
use RuntimeException;

/**
 * Sitting an indfødsretsprøve.
 *
 * Only the start is its own endpoint. Everything afterwards -- serving the paper,
 * recording an answer, handing in, reading the review -- is the session API under
 * /reading/sessions, because a session is one resource whatever kind of paper it froze,
 * and a second copy of those four endpoints would be four more places for the rule
 * "never serve the answer" to be got wrong.
 */
final class IndfoedsretController
{
    public function __construct(
        private ReadingSessionService $reading = new ReadingSessionService(),
        private ReadingRepository $papers = new ReadingRepository(),
    ) {}

    public function papers(): void
    {
        Response::json([
            'papers' => array_map(
                static fn(array $p): array => [
                    'slug'         => $p['slug'],
                    'title'        => $p['title'],
                    'questions'    => (int) $p['questions'],
                    'aktuelle'     => (int) $p['aktuelle'],
                    'pass'         => $p['pass'] === null ? null : (int) $p['pass'],
                    'vaerdier_min' => $p['vaerdier_min'] === null ? null : (int) $p['vaerdier_min'],
                ],
                $this->papers->publishedPapers()
            ),
        ]);
    }

    public function start(array $body): void
    {
        $slug = isset($body['slug']) && is_string($body['slug']) && $body['slug'] !== ''
            ? $body['slug']
            : null;

        // Absent means the whole paper. Only an explicit false leaves the block out, so
        // a client that has never heard of the option still sits the exam as it is set.
        $currentAffairs = ($body['current_affairs'] ?? true) !== false;

        try {
            $session = $this->reading->startPaper(Auth::userId(), Auth::anonKey(), $slug, $currentAffairs);
            Response::json($this->reading->session($session['session_id']));
        } catch (RuntimeException $e) {
            Response::error('cannot_build_round', $e->getMessage(), 409);
        }
    }
}
