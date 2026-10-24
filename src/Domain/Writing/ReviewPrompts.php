<?php declare(strict_types=1);

namespace Dansk\Domain\Writing;

/** The prompt for a task, by its kind. */
final class ReviewPrompts
{
    /** @param array<string,mixed> $task */
    public static function for(array $task): ReviewPrompt
    {
        return $task['kind'] === 'connectors' ? new ConnectorPrompt() : new WritingPrompt();
    }
}
