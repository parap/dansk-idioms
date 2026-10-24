<?php declare(strict_types=1);

namespace Dansk\Domain\Writing;

/**
 * The Modultest writing tasks, read from the file the writing page itself shows, so a
 * check is always about the task the learner was looking at.
 */
final class WritingTasks
{
    /** The test's own minimum for each task. */
    private const MINIMUM = ['email' => 125, 'blog' => 100];

    /** The page's word pattern: a hyphen or apostrophe inside a word keeps it one word. */
    private const WORD = "/[\\p{L}\\p{N}]+(?:[-'’][\\p{L}\\p{N}]+)*/u";

    public function __construct(private string $file = __DIR__ . '/../../../public/skriv-tal.json') {}

    /** @return ?array{id:string,kind:string,minimum:int,from:string,title:?string,text:string,questions:list<string>} */
    public function find(string $id): ?array
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
