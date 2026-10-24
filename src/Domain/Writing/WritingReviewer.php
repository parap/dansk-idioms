<?php declare(strict_types=1);

namespace Dansk\Domain\Writing;

/** Reads a learner's text against its task and says what is right and wrong in it. */
interface WritingReviewer
{
    /**
     * @param  array<string,mixed> $task a task as WritingTasks::find() returns it
     * @return array{feedback:array<string,mixed>,model:string,input_tokens:int,output_tokens:int}
     * @throws \RuntimeException when no review came back
     */
    public function review(array $task, string $text, string $lang): array;
}
