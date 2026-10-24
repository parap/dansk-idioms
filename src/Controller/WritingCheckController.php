<?php declare(strict_types=1);

namespace Dansk\Controller;

use Anthropic\Core\Exceptions\APIException;
use Dansk\Domain\Writing\WritingCheckService;
use Dansk\Domain\Writing\WritingCheckUnavailable;
use Dansk\Domain\Writing\WritingLimitReached;
use Dansk\Http\Response;
use Dansk\Support\Auth;
use InvalidArgumentException;
use RuntimeException;

/** A Modultest letter or blog post sent for review from /modultest/skriv. */
final class WritingCheckController
{
    /** A review takes up to a minute or two, well past PHP's default limit. */
    private const SECONDS = 180;

    public function __construct(private ?WritingCheckService $checks = null) {}

    public function check(array $body): void
    {
        $field = static fn(string $name): string => isset($body[$name]) && is_string($body[$name]) ? $body[$name] : '';
        set_time_limit(self::SECONDS);

        try {
            Response::json(($this->checks ?? WritingCheckService::fromConfig())
                ->check(Auth::userId(), Auth::anonKey(), $field('task'), $field('text'), $field('lang')));
        } catch (WritingCheckUnavailable $e) {
            Response::error('unavailable', $e->getMessage(), 503);
        } catch (WritingLimitReached $e) {
            Response::error('limit_' . $e->getMessage(), 'Today\'s checks are used up.', 429);
        } catch (InvalidArgumentException $e) {
            Response::error('invalid', $e->getMessage(), 422);
        } catch (APIException | RuntimeException $e) {
            // The cause goes to the log; the learner is told only that it did not work.
            error_log('writing check: ' . $e->getMessage());
            Response::error('review_failed', 'The review did not come back.', 502);
        }
    }
}
