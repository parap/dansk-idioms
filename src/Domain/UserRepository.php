<?php declare(strict_types=1);

namespace Dansk\Domain;

use Dansk\Support\Db;

final class UserRepository
{
    public function register(string $email, string $password, ?string $displayName, ?string $anonKey): int
    {
        $email = mb_strtolower(trim($email), 'UTF-8');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('That is not a valid email address.');
        }
        if (mb_strlen($password, 'UTF-8') < 8) {
            throw new \InvalidArgumentException('Password must be at least 8 characters.');
        }
        if (Db::fetchValue('SELECT id FROM users WHERE email = ?', [$email]) !== false) {
            throw new \InvalidArgumentException('That email is already registered.');
        }

        Db::execute(
            'INSERT INTO users (email, password_hash, display_name) VALUES (?,?,?)',
            [$email, password_hash($password, PASSWORD_ARGON2ID), $displayName ?: null]
        );
        $id = (int) Db::pdo()->lastInsertId();

        // Adopt the rounds played before signing up, so progress is not lost.
        if ($anonKey !== null) {
            Db::execute(
                'UPDATE quiz_sessions SET user_id = ? WHERE anon_key = ? AND user_id IS NULL',
                [$id, $anonKey]
            );
        }
        return $id;
    }

    public function authenticate(string $email, string $password): ?int
    {
        $row = Db::fetchOne(
            'SELECT id, password_hash FROM users WHERE email = ?',
            [mb_strtolower(trim($email), 'UTF-8')]
        );
        // Hash even when the user is unknown, so response time does not reveal it.
        $hash = $row['password_hash'] ?? '$argon2id$v=19$m=65536,t=4,p=1$aaaaaaaaaaaaaaaa$aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        if (!password_verify($password, $hash) || $row === null) {
            return null;
        }
        Db::execute('UPDATE users SET last_seen_at = NOW() WHERE id = ?', [(int) $row['id']]);
        return (int) $row['id'];
    }

    public function profile(int $userId): ?array
    {
        $u = Db::fetchOne('SELECT id, email, display_name, ui_lang FROM users WHERE id = ?', [$userId]);
        if ($u === null) {
            return null;
        }
        return $u + [
            'rounds'  => (int) Db::fetchValue(
                "SELECT COUNT(*) FROM quiz_sessions WHERE user_id = ? AND status = 'finished'", [$userId]
            ),
            'learned' => (int) Db::fetchValue(
                'SELECT COUNT(*) FROM user_idiom_progress WHERE user_id = ? AND repetitions >= 2', [$userId]
            ),
            'due'     => (int) Db::fetchValue(
                'SELECT COUNT(*) FROM user_idiom_progress WHERE user_id = ? AND due_at <= NOW()', [$userId]
            ),
        ];
    }
}
