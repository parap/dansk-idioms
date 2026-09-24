<?php declare(strict_types=1);

namespace Dansk\Tests\Support;

use Dansk\Support\BotApi;
use Dansk\Support\BotApiError;
use PHPUnit\Framework\TestCase;

/**
 * The Telegram Bot API client. The transport is replaced; there is no network here.
 *
 * What is checked is the silent case: Telegram answers `200 OK` to a refusal too,
 * reporting it as `ok: false` inside the body. A client reading the status code would
 * count an unpublished post as published.
 */
final class BotApiTest extends TestCase
{
    public function testAMessageGoesToTheNamedChat(): void
    {
        $sent = [];
        $api = new BotApi('токен', function (string $method, array $params) use (&$sent): array {
            $sent = [$method, $params];

            return ['ok' => true, 'result' => ['message_id' => 7]];
        });

        $api->sendMessage('-100500', "at spille\n\n#text");

        self::assertSame('sendMessage', $sent[0]);
        self::assertSame('-100500', $sent[1]['chat_id']);
        self::assertSame("at spille\n\n#text", $sent[1]['text']);
    }

    public function testTheMessageIdComesBack(): void
    {
        $api = new BotApi('токен', fn () => ['ok' => true, 'result' => ['message_id' => 7]]);

        self::assertSame(7, $api->sendMessage('-100500', 'привет'));
    }

    public function testARefusalInsideTwoHundredIsStillARefusal(): void
    {
        // Telegram answers 200 and puts the refusal in the body. A client reading
        // only the status code would report a success that never happened.
        $api = new BotApi('токен', fn () => [
            'ok' => false, 'description' => 'Bad Request: chat not found',
        ]);

        $this->expectException(BotApiError::class);
        $this->expectExceptionMessage('chat not found');
        $api->sendMessage('-100500', 'привет');
    }

    public function testAnUnsetTokenRefusesBeforeTheCall(): void
    {
        // An unset token is a switched-off feature. Without the check the request
        // would go to /bot/sendMessage and return 404, with the cause in the config.
        $called = false;
        $api = new BotApi(null, function () use (&$called) { $called = true; return []; });

        try {
            $api->sendMessage('-100500', 'привет');
            self::fail('a refusal was expected');
        } catch (BotApiError) {
            self::assertFalse($called, 'it must not reach the network');
        }
    }

    // --- buttons --------------------------------------------------------------

    public function testAPressIsAcknowledged(): void
    {
        // Unanswered, Telegram spins on the button for half a minute and the person
        // presses again -- which is the one thing publishing must not invite.
        $sent = [];
        $api = new BotApi('token', function (string $method, array $params) use (&$sent): array {
            $sent = [$method, $params];

            return ['ok' => true, 'result' => true];
        });

        $api->answerCallback('cb-1', 'Опубликовала');

        self::assertSame('answerCallbackQuery', $sent[0]);
        self::assertSame('cb-1', $sent[1]['callback_query_id']);
        self::assertSame('Опубликовала', $sent[1]['text']);
    }

    public function testTheButtonsCanBeTakenAway(): void
    {
        // A live button under a decision already made says the decision was not made.
        $sent = [];
        $api = new BotApi('token', function (string $method, array $params) use (&$sent): array {
            $sent = [$method, $params];

            return ['ok' => true, 'result' => true];
        });

        $api->editReplyMarkup('158493465', 42, ['inline_keyboard' => []]);

        self::assertSame('editMessageReplyMarkup', $sent[0]);
        self::assertSame(42, $sent[1]['message_id']);
        self::assertSame('{"inline_keyboard":[]}', $sent[1]['reply_markup']);
    }

    public function testAMessageCanCarryButtons(): void
    {
        $sent = [];
        $api = new BotApi('token', function (string $method, array $params) use (&$sent): array {
            $sent = [$method, $params];

            return ['ok' => true, 'result' => ['message_id' => 9]];
        });

        $api->sendMessage('1', 'выбери', [], ['inline_keyboard' => [[['text' => 'да', 'callback_data' => 'x']]]]);

        self::assertStringContainsString('callback_data', $sent[1]['reply_markup']);
    }
}
