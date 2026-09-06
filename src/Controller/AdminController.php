<?php declare(strict_types=1);

namespace Dansk\Controller;

use Dansk\Domain\ReviewRepository;
use Dansk\Http\Response;
use Dansk\Support\Config;

final class AdminController
{
    public function __construct(private ReviewRepository $repo = new ReviewRepository()) {}

    public static function startSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'path' => '/']);
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
        $expected = (string) Config::get('admin.password');
        $given    = (string) ($body['password'] ?? '');

        // hash_equals: constant time, so the response cannot be timed for the secret.
        if ($given === '' || !hash_equals($expected, $given)) {
            usleep(300_000);
            Response::error('bad_credentials', 'Wrong password.', 401);
            return;
        }
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
