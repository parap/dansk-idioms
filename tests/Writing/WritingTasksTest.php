<?php declare(strict_types=1);

namespace Dansk\Tests\Writing;

use Dansk\Domain\Writing\WritingTasks;
use PHPUnit\Framework\TestCase;

/** The writing tasks the page offers are the ones a check is asked about, read from the same file. */
final class WritingTasksTest extends TestCase
{
    public function testAnEmailTaskCarriesTheMailItsQuestionsAndItsMinimum(): void
    {
        $task = (new WritingTasks())->find('e1');

        self::assertSame('email', $task['kind']);
        self::assertSame(125, $task['minimum']);
        self::assertSame('Sune', $task['from']);
        self::assertStringContainsString('Hvordan går det?', $task['text']);
        self::assertCount(3, $task['questions']);
    }

    public function testABlogTaskCarriesThePostItsTitleAndItsMinimum(): void
    {
        $task = (new WritingTasks())->find('b1');

        self::assertSame('blog', $task['kind']);
        self::assertSame(100, $task['minimum']);
        self::assertSame('Forbyd rygning', $task['title']);
        self::assertSame('Helle Christensen', $task['from']);
        self::assertSame([], $task['questions']);
    }

    /** A connector task: write sentences with the connectors of one group, the hints as a start. */
    public function testAConnectorTaskCarriesItsGroupConnectorsAndHints(): void
    {
        $task = (new WritingTasks())->find('fo-konsekvens');

        self::assertSame('connectors', $task['kind']);
        self::assertSame('Konsekvens', $task['title']);
        self::assertContains('derfor', $task['connectors']);
        self::assertCount(3, $task['questions']);
        self::assertSame([4, 300], [$task['fewest'], $task['most']]);
    }

    /** A letter is refused under ten words; a sentence or two with a connector is not. */
    public function testATaskNamesTheLengthItTakes(): void
    {
        $email = (new WritingTasks())->find('e1');

        self::assertSame([10, 600], [$email['fewest'], $email['most']]);
    }

    public function testAnUnknownTaskIsNone(): void
    {
        self::assertNull((new WritingTasks())->find('x9'));
        self::assertNull((new WritingTasks())->find(''));
    }

    /** The count the page shows is the count the server judges by. */
    public function testCountsWordsAsThePageDoes(): void
    {
        self::assertSame(6, WritingTasks::words("Hej Sune!\n\nTak for din e-mail."));
        self::assertSame(4, WritingTasks::words("Det er Mette's bil"));
        self::assertSame(0, WritingTasks::words("  \n "));
    }
}
