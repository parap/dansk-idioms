<?php declare(strict_types=1);

namespace Dansk\Domain\Writing;

/** What the reviewer is asked about one kind of task, and the shape its answer must take. */
interface ReviewPrompt
{
    /** The same for every check of this kind; the task and the text travel in user(). */
    public function system(): string;

    /** @param array<string,mixed> $task a task as WritingTasks::find() returns it */
    public function user(array $task, string $text, string $lang): string;

    /** @return array<string,mixed> the JSON schema the answer is held to */
    public function schema(): array;

    /**
     * @return array<string,mixed> the answer, checked to have every part the page draws
     * @throws \UnexpectedValueException when it does not
     */
    public function parse(string $json): array;
}
