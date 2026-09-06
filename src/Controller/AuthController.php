<?php declare(strict_types=1);

namespace Dansk\Controller;

use Dansk\Domain\UserRepository;
use Dansk\Http\Response;
use Dansk\Support\Auth;

final class AuthController
{
    public function __construct(private UserRepository $users = new UserRepository()) {}

    public function register(array $body): void
    {
        try {
            $id = $this->users->register(
                (string) ($body['email'] ?? ''),
                (string) ($body['password'] ?? ''),
                isset($body['display_name']) ? (string) $body['display_name'] : null,
                Auth::anonKey()
            );
            Auth::login($id);
            Response::json(['ok' => true, 'user' => $this->users->profile($id)]);
        } catch (\InvalidArgumentException $e) {
            Response::error('invalid', $e->getMessage(), 422);
        }
    }

    public function login(array $body): void
    {
        $id = $this->users->authenticate((string) ($body['email'] ?? ''), (string) ($body['password'] ?? ''));
        if ($id === null) {
            Response::error('bad_credentials', 'Wrong email or password.', 401);
            return;
        }
        Auth::login($id);
        Response::json(['ok' => true, 'user' => $this->users->profile($id)]);
    }

    public function logout(): void
    {
        Auth::logout();
        Response::json(['ok' => true]);
    }

    public function me(): void
    {
        $id = Auth::userId();
        Response::json(['user' => $id === null ? null : $this->users->profile($id)]);
    }
}
