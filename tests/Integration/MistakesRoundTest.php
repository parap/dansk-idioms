<?php declare(strict_types=1);

namespace Dansk\Tests\Integration;

use Dansk\Domain\Reading\PassageDocument;
use Dansk\Domain\Reading\ReadingRepository;
use Dansk\Domain\Reading\ReadingSessionService;
use Dansk\Import\Indfoedsret\AnswerKey;
use Dansk\Import\Indfoedsret\PaperDocument;
use Dansk\Import\Indfoedsret\PaperParser;
use Dansk\Support\Db;
use RuntimeException;

/**
 * Going over what went wrong.
 *
 * Sitting whole papers again spends most of the time on questions already known. A round
 * built from the questions this learner last got wrong spends it on the rest.
 *
 * What counts is the *latest* record of a question on a paper handed in: a question got
 * wrong in May and right in June is learned, and putting it back would teach the learner
 * nothing except that the site does not notice.
 */
final class MistakesRoundTest extends IntegrationTestCase
{
    private const MINE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const THEIRS = 'ffffffffffffffffffffffffffffffff';
    private const QUIZ = ['quiz'];

    private ReadingSessionService $service;
    private ReadingRepository $repo;
    private string $slug;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ReadingSessionService();
        $this->repo    = new ReadingRepository();

        $dir = dirname(__DIR__) . '/fixtures/indfoedsret/';
        $doc = (new PassageDocument())->parse((new PaperDocument())->render(
            (new PaperParser())->parse((string) file_get_contents($dir . 'paper-2026-06.txt')),
            (new AnswerKey())->parse((string) file_get_contents($dir . 'key-2026-06.xml'))
        ));
        $this->repo->publish($this->repo->save($doc));
        $this->slug = $doc['slug'];
    }

    private function correctIndex(string $sid, int $position): int
    {
        return (int) Db::fetchValue(
            'SELECT si.correct_index FROM reading_session_items si
             JOIN reading_sessions s ON s.id = si.session_id
             WHERE s.public_id = ? AND si.position = ?',
            [$sid, $position]
        );
    }

    private function itemIdAt(string $sid, int $position): int
    {
        return (int) Db::fetchValue(
            'SELECT si.item_id FROM reading_session_items si
             JOIN reading_sessions s ON s.id = si.session_id
             WHERE s.public_id = ? AND si.position = ?',
            [$sid, $position]
        );
    }

    /**
     * Sits the paper, answering the named positions wrongly and leaving the blank ones
     * unanswered, then hands it in.
     *
     * @param list<int> $wrongAt
     * @param list<int> $blankAt
     */
    private function sit(string $anon, array $wrongAt = [], array $blankAt = []): string
    {
        $sid = $this->service->startPaper(null, $anon, $this->slug, true)['session_id'];
        foreach ($this->service->session($sid)['items'] as $item) {
            $position = $item['position'];
            if (in_array($position, $blankAt, true)) {
                continue;
            }
            $correct = $this->correctIndex($sid, $position);
            $chosen  = in_array($position, $wrongAt, true)
                ? ($correct + 1) % count($item['options'])
                : $correct;
            $this->service->answer($sid, $position, $chosen);
        }
        $this->service->submit($sid);

        return $sid;
    }

    /** @return list<int> the item ids a mistakes round would be built from */
    private function waiting(string $anon = self::MINE): array
    {
        $sid = $this->service->startMistakes(null, $anon, self::QUIZ)['session_id'];
        $ids = Db::fetchAll(
            'SELECT si.item_id FROM reading_session_items si
             JOIN reading_sessions s ON s.id = si.session_id
             WHERE s.public_id = ? ORDER BY si.position',
            [$sid]
        );

        return array_map('intval', array_column($ids, 'item_id'));
    }

    public function testAQuestionAnsweredWronglyComesBack(): void
    {
        $sid  = $this->sit(self::MINE, wrongAt: [2, 5]);
        $want = [$this->itemIdAt($sid, 2), $this->itemIdAt($sid, 5)];

        self::assertSame(2, $this->service->mistakesWaiting(null, self::MINE, self::QUIZ));
        self::assertEqualsCanonicalizing($want, $this->waiting());
    }

    public function testAQuestionLeftBlankComesBackToo(): void
    {
        // The paper was handed in with it empty, and it counted against the learner
        // exactly as a wrong answer did.
        $sid = $this->sit(self::MINE, blankAt: [3]);

        self::assertSame([$this->itemIdAt($sid, 3)], $this->waiting());
    }

    public function testAQuestionAnsweredCorrectlyDoesNotComeBack(): void
    {
        $this->sit(self::MINE);

        self::assertSame(0, $this->service->mistakesWaiting(null, self::MINE, self::QUIZ));
    }

    public function testAQuestionGotWrongAndThenRightIsLearned(): void
    {
        $first   = $this->sit(self::MINE, wrongAt: [4, 7]);
        $learned = $this->itemIdAt($first, 4);
        $still   = $this->itemIdAt($first, 7);
        Db::execute(
            'UPDATE reading_sessions SET submitted_at = DATE_SUB(submitted_at, INTERVAL 1 DAY)
              WHERE public_id = ?',
            [$first]
        );
        // The same paper again, this time getting 4 right and 7 wrong once more.
        $this->sit(self::MINE, wrongAt: [7]);

        $waiting = $this->waiting();
        self::assertContains($still, $waiting, 'a question still being got wrong was dropped');
        self::assertNotContains($learned, $waiting, 'a question since got right came back');
    }

    public function testAnotherBrowsersMistakesAreNotMine(): void
    {
        $this->sit(self::THEIRS, wrongAt: [2]);

        self::assertSame(0, $this->service->mistakesWaiting(null, self::MINE, self::QUIZ));
    }

    public function testAPaperStillBeingSatContributesNothing(): void
    {
        $sid = $this->service->startPaper(null, self::MINE, $this->slug, true)['session_id'];
        $this->service->answer($sid, 1, ($this->correctIndex($sid, 1) + 1) % 2);

        self::assertSame(0, $this->service->mistakesWaiting(null, self::MINE, self::QUIZ));
    }

    public function testThereIsNothingToGoOverWhenNothingWentWrong(): void
    {
        $this->sit(self::MINE);

        $this->expectException(RuntimeException::class);
        $this->service->startMistakes(null, self::MINE, self::QUIZ);
    }

    public function testAMistakesRoundIsAnswerableAndScoredLikeAnyOther(): void
    {
        $this->sit(self::MINE, wrongAt: [2, 5]);

        $sid = $this->service->startMistakes(null, self::MINE, self::QUIZ)['session_id'];
        $session = $this->service->session($sid);
        self::assertCount(2, $session['items']);
        foreach ($session['items'] as $item) {
            // Never the key: a round built from past answers must not leak them either.
            self::assertArrayNotHasKey('correct_index', $item);
            $this->service->answer($sid, $item['position'], $this->correctIndex($sid, $item['position']));
        }
        $result = $this->service->submit($sid);

        self::assertSame($result['points_max'], $result['points_scored']);
        // A handful of questions is not the paper, so it gets no verdict of its own.
        self::assertNull($result['verdict']);
    }
}
