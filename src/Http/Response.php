<?php declare(strict_types=1);

namespace Dansk\Http;

final class Response
{
    public static function json(mixed $data, int $status = 200, array $headers = []): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        foreach ($headers as $name => $value) {
            header("$name: $value");
        }
        echo json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    public static function error(string $code, string $message, int $status = 400): void
    {
        self::json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
