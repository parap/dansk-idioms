<?php declare(strict_types=1);

namespace Dansk\Controller;

use Dansk\Domain\Reading\ReadingRepository;
use Dansk\Domain\Reading\ReadingSessionService;
use Dansk\Http\Response;
use Dansk\Support\Auth;
use RuntimeException;

/**
 * Listening tasks: a film and the questions about it.
 *
 * Only the list and the start are their own endpoints; answering, handing in and the
 * review go through the session API under /reading/sessions, as a knowledge paper does.
 */
final class VideoController
{
    public function __construct(
        private ReadingSessionService $reading = new ReadingSessionService(),
        private ReadingRepository $videos = new ReadingRepository(),
    ) {}

    public function list(): void
    {
        Response::json(['videos' => $this->videos->publishedVideos()]);
    }

    public function start(array $body): void
    {
        $slug = isset($body['slug']) && is_string($body['slug']) ? $body['slug'] : '';

        try {
            $session = $this->reading->startVideo(Auth::userId(), Auth::anonKey(), $slug);
            Response::json($this->reading->session($session['session_id']));
        } catch (RuntimeException $e) {
            Response::error('not_found', $e->getMessage(), 404);
        }
    }
}
