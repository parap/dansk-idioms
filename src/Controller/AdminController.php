<?php declare(strict_types=1);

namespace Dansk\Controller;

use Dansk\Domain\ReviewRepository;
use Dansk\Http\Response;
use Dansk\Support\AdminLoginThrottle;
use Dansk\Support\Config;
use Dansk\Support\Scheme;

final class AdminController
{
    public function __construct(
        private ReviewRepository $repo = new ReviewRepository(),
        private AdminLoginThrottle $throttle = new AdminLoginThrottle(),
    ) {}

    public static function startSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_set_cookie_params(Scheme::cookieParams($_SERVER));
            session_start();
        }
    }

    public static function isAuthenticated(): bool
    {
        self::startSession();
        return ($_SESSION['admin'] ?? false) === true;
    }

    public function login(array $body): void
    {
        self::startSession();
        $expected = Config::get('admin.password');
        $given    = (string) ($body['password'] ?? '');

        // No password configured means the admin area is closed, not open. Failing
        // shut is the only safe behaviour: a default that happens to work is a
        // published credential as soon as the repository is.
        if (!is_string($expected) || $expected === '') {
            Response::error(
                'admin_disabled',
                'No admin password is configured. Set ADMIN_PASSWORD or admin.password in config/local.php.',
                503
            );
            return;
        }

        // Checked before the password, so a locked-out caller learns nothing from how
        // long the answer took.
        $client = AdminLoginThrottle::clientFrom($_SERVER);
        $wait   = $this->throttle->retryAfter($client);
        if ($wait !== null) {
            header('Retry-After: ' . $wait);
            Response::error(
                'too_many_attempts',
                "Too many failed attempts. Try again in {$wait} seconds.",
                429
            );
            return;
        }

        // hash_equals: constant time, so the response cannot be timed for the secret.
        if ($given === '' || !hash_equals($expected, $given)) {
            $this->throttle->recordFailure($client);
            usleep(300_000);
            Response::error('bad_credentials', 'Wrong password.', 401);
            return;
        }

        $this->throttle->clear($client);
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        Response::json(['ok' => true]);
    }

    public function logout(): void
    {
        self::startSession();
        $_SESSION = [];
        session_destroy();
        Response::json(['ok' => true]);
    }

    public function queue(): void
    {
        Response::json([
            'counts' => $this->repo->counts(),
            'items'  => $this->repo->queue(200),
        ]);
    }

    public function accept(int $id, array $body): void
    {
        try {
            $result = $this->repo->accept(
                $id,
                (string) ($body['term'] ?? ''),
                (string) ($body['translation'] ?? ''),
                array_values(array_filter(
                    (array) ($body['extra'] ?? []),
                    static fn($v): bool => is_string($v) && trim($v) !== ''
                ))
            );
            Response::json(['ok' => true] + $result);
        } catch (\InvalidArgumentException $e) {
            Response::error('invalid', $e->getMessage(), 422);
        } catch (\Throwable $e) {
            error_log((string) $e);
            Response::error('server_error', Config::get('debug') ? $e->getMessage() : 'Failed.', 500);
        }
    }

    public function reject(int $id): void
    {
        $this->repo->reject($id);
        Response::json(['ok' => true]);
    }
}
