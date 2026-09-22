<?php declare(strict_types=1);

namespace Dansk\Support;

/** Telegram refused -- or would have, were it configured. */
final class BotApiError extends \RuntimeException
{
}

/**
 * A client for the Telegram Bot API: exactly what the publisher needs.
 *
 * **A refusal arrives inside a successful response.** Telegram answers `200 OK` to an
 * error too, reporting it as `ok: false` in the body. A client that reads the status
 * code would count an unpublished post as published, so the body decides, not the code.
 *
 * The transport is replaceable: the tests check what goes where without a network.
 */
final class BotApi
{
    private const BASE = 'https://api.telegram.org';

    /** @var callable(string, array): array */
    private $transport;

    public function __construct(private ?string $token, ?callable $transport = null)
    {
        $this->transport = $transport ?? $this->curl(...);
    }

    /** Send text. Returns the message_id of what was published. */
    public function sendMessage(string $chatId, string $text): int
    {
        $answer = $this->call('sendMessage', ['chat_id' => $chatId, 'text' => $text]);

        return (int) ($answer['result']['message_id'] ?? 0);
    }

    private function call(string $method, array $params): array
    {
        // An unset token is a switched-off feature, not a reason to reach the network:
        // the request would go to /bot/<method> and come back 404, while the cause sat
        // in the configuration. Refusing here names it instead.
        if ($this->token === null || $this->token === '') {
            throw new BotApiError('communicator.token is unset: nothing to publish with.');
        }

        $answer = ($this->transport)($method, $params);
        if (($answer['ok'] ?? false) !== true) {
            $why = (string) ($answer['description'] ?? 'no description given');
            throw new BotApiError("Telegram refused {$method}: {$why}");
        }

        return $answer;
    }

    /** @return array<string,mixed> */
    private function curl(string $method, array $params): array
    {
        $ch = curl_init(self::BASE . '/bot' . $this->token . '/' . $method);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
        ]);
        $body  = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new BotApiError("Telegram unreachable on {$method}: {$error}");
        }

        return json_decode((string) $body, true) ?? [];
    }
}
