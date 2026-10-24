<?php declare(strict_types=1);

namespace Dansk\Controller;

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
    /** The reviewer waits 170 seconds for the host worker; the request outlives the wait. */
    private const SECONDS = 180;

    public function __construct(private ?WritingCheckService $checks = null) {}

    /** Whether this visitor is offered the check, so the page shows the button only then. */
    public function available(): void
    {
        Response::json($this->service()->availability(Auth::userId()));
    }

    public function check(array $body): void
    {
        $field = static fn(string $name): string => isset($body[$name]) && is_string($body[$name]) ? $body[$name] : '';
        set_time_limit(self::SECONDS);

        try {
            Response::json($this->service()->check(
                Auth::userId(), $field('task'), $field('text'), $field('lang'),
                $field('model') ?: WritingCheckService::MODELS[0]
            ));
        } catch (WritingCheckUnavailable $e) {
            Response::error('unavailable', $e->getMessage(), 403);
        } catch (WritingLimitReached $e) {
            Response::error('limit', $e->getMessage(), 429);
        } catch (InvalidArgumentException $e) {
            Response::error('invalid', $e->getMessage(), 422);
        } catch (RuntimeException $e) {
            // The cause goes to the log; the page says only that it did not work.
            error_log('writing check: ' . $e->getMessage());
            Response::error('review_failed', 'The review did not come back.', 502);
        }
    }

    private function service(): WritingCheckService
    {
        return $this->checks ?? WritingCheckService::fromConfig();
    }
}
