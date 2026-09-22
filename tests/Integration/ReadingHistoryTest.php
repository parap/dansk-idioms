<?php declare(strict_types=1);

namespace Dansk\Tests\Integration;

use Dansk\Domain\Reading\PassageDocument;
use Dansk\Domain\Reading\ReadingRepository;
use Dansk\Domain\Reading\ReadingSessionService;
use Dansk\Import\Indfoedsret\AnswerKey;
use Dansk\Import\Indfoedsret\PaperDocument;
use Dansk\Import\Indfoedsret\PaperParser;
use Dansk\Support\Db;

/**
 * What a learner has already sat.
 *
 * Every sitting is recorded as it happens, against an account or, without one, against
 * the browser's cookie. Until something reads those rows back a learner finishes a paper,
 * reads a verdict once, and has no way to find out afterwards which sittings they have
 * done or whether they are getting better.
 */
final class ReadingHistoryTest extends IntegrationTestCase
{
    private const MINE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const THEIRS = 'ffffffffffffffffffffffffffffffff';

    private ReadingSessionService $service;
    private ReadingRepository $repo;
    private string $slug;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ReadingSessionService();
        $this->repo    = new ReadingRepository();
        $this->slug    = $this->publishPaper();
    }

    private function publishPaper(): string
    {
        $dir = dirname(__DIR__) . '/fixtures/indfoedsret/';
        $doc = (new PassageDocument())->parse((new PaperDocument())->render(
            (new PaperParser())->parse((string) file_get_contents($dir . 'paper-2026-06.txt')),
            (new AnswerKey())->parse((string) file_get_contents($dir . 'key-2026-06.xml'))
        ));
        $this->repo->publish($this->repo->save($doc));

        return $doc['slug'];
    }

    /** Sits the whole paper correctly and hands it in. */
    private function sit(string $anon): string
    {
        $sid = $this->service->startPaper(null, $anon, $this->slug, true)['session_id'];
        foreach ($this->service->session($sid)['items'] as $item) {
            $this->service->answer($sid, $item['position'], (int) Db::fetchValue(
                'SELECT si.correct_index FROM reading_session_items si
                 JOIN reading_sessions s ON s.id = si.session_id
                 WHERE s.public_id = ? AND si.position = ?',
                [$sid, $item['position']]
            ));
        }
        $this->service->submit($sid);

        return $sid;
    }

    public function testASittingHandedInIsListedWithItsPaperScoreAndVerdict(): void
    {
        $sid = $this->sit(self::MINE);

        $history = $this->service->history(null, self::MINE);

        self::assertCount(1, $history);
        self::assertSame($sid, $history[0]['session_id']);
        self::assertStringContainsString('Indfødsretsprøven', $history[0]['title']);
        self::assertSame('bestaaet', $history[0]['verdict']);
        self::assertSame($history[0]['points_max'], $history[0]['points_scored']);
        self::assertNotNull($history[0]['submitted_at']);
    }

    public function testASittingStillInProgressIsNotHistoryYet(): void
    {
        $this->service->startPaper(null, self::MINE, $this->slug, true);

        self::assertSame([], $this->service->history(null, self::MINE));
    }

    public function testAnotherBrowsersSittingsAreNotMine(): void
    {
        $this->sit(self::THEIRS);

        self::assertSame([], $this->service->history(null, self::MINE));
    }

    public function testTheMostRecentSittingComesFirst(): void
    {
        $first = $this->sit(self::MINE);
        // submitted_at has one-second resolution, so two sittings inside the same second
        // would order by whatever the engine felt like.
        Db::execute(
            'UPDATE reading_sessions SET submitted_at = DATE_SUB(submitted_at, INTERVAL 1 DAY)
              WHERE public_id = ?',
            [$first]
        );
        $second = $this->sit(self::MINE);

        $history = $this->service->history(null, self::MINE);

        self::assertSame([$second, $first], array_column($history, 'session_id'));
    }

    public function testASignedInLearnerSeesTheirOwnSittingsAndNotTheBrowsersOthers(): void
    {
        $this->sit(self::THEIRS);
        $sid = $this->sit(self::MINE);
        Db::execute('INSERT INTO users (email, password_hash) VALUES (?,?)', ['a@b.dk', 'x']);
        $userId = (int) Db::pdo()->lastInsertId();
        Db::execute('UPDATE reading_sessions SET user_id = ? WHERE public_id = ?', [$userId, $sid]);

        $history = $this->service->history($userId, self::THEIRS);

        self::assertSame([$sid], array_column($history, 'session_id'));
    }
}
