<?php declare(strict_types=1);

namespace Dansk\Domain\Writing;

/**
 * The writing tasks, read from the files the pages themselves show, so a check is always
 * about the task the learner was looking at: the Modultest letters and blog posts, and the
 * connector groups of /forbinderord, where the learner writes sentences of their own.
 */
final class WritingTasks
{
    /** The test's own minimum for each task. */
    private const MINIMUM = ['email' => 125, 'blog' => 100];

    /** The words a text of each kind may run to: a letter from ten, a connector sentence from four. */
    private const LENGTH = ['email' => [10, 600], 'blog' => [10, 600], 'connectors' => [4, 300]];

    /** The page's word pattern: a hyphen or apostrophe inside a word keeps it one word. */
    private const WORD = "/[\\p{L}\\p{N}]+(?:[-'’][\\p{L}\\p{N}]+)*/u";

    public function __construct(
        private string $file = __DIR__ . '/../../../public/skriv-tal.json',
        private string $connectorFile = __DIR__ . '/../../../public/forbinderord-skriv.json',
    ) {}

    /**
     * A connector task's questions are its hints, and its text the connectors it asks for.
     *
     * @return ?array{id:string,kind:string,minimum:int,fewest:int,most:int,from:string,title:?string,text:string,questions:list<string>}
     */
    public function find(string $id): ?array
    {
        $task = $this->letter($id) ?? $this->connectorGroup($id);

        return $task === null ? null : $task + ['fewest' => self::LENGTH[$task['kind']][0], 'most' => self::LENGTH[$task['kind']][1]];
    }

    /** @return ?array<string,mixed> */
    private function connectorGroup(string $id): ?array
    {
        $data = json_decode((string) file_get_contents($this->connectorFile), true, flags: JSON_THROW_ON_ERROR);
        foreach ($data['groups'] as $group) {
            if ($group['id'] === $id) {
                return [
                    'id' => $id, 'kind' => 'connectors', 'minimum' => 0, 'from' => '', 'title' => $group['title'],
                    'text' => implode(', ', $group['connectors']), 'connectors' => $group['connectors'], 'questions' => $group['hints'],
                ];
            }
        }

        return null;
    }

    /** @return ?array<string,mixed> */
    private function letter(string $id): ?array
    {
        $data = json_decode((string) file_get_contents($this->file), true, flags: JSON_THROW_ON_ERROR);

        foreach ($data['emails'] as $mail) {
            if ($mail['id'] === $id) {
                return [
                    'id' => $id, 'kind' => 'email', 'minimum' => self::MINIMUM['email'], 'from' => $mail['from'],
                    'title' => null, 'text' => $mail['mail'], 'questions' => $mail['questions'],
                ];
            }
        }
        foreach ($data['blogs'] as $post) {
            if ($post['id'] === $id) {
                return [
                    'id' => $id, 'kind' => 'blog', 'minimum' => self::MINIMUM['blog'], 'from' => $post['author'],
                    'title' => $post['title'], 'text' => $post['post'], 'questions' => [],
                ];
            }
        }

        return null;
    }

    public static function words(string $text): int
    {
        return (int) preg_match_all(self::WORD, $text);
    }
}
