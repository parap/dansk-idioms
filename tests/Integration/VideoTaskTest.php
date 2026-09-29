<?php declare(strict_types=1);

namespace Dansk\Tests\Integration;

use Dansk\Domain\Reading\PassageDocument;
use Dansk\Domain\Reading\ReadingRepository;
use Dansk\Domain\Reading\ReadingSessionService;
use RuntimeException;

/**
 * A listening task: a film and the questions about it, sat as a drill so every answer is
 * checked at once -- the learner is still watching, and a mark at the end would come too
 * late to send them back to the right minute.
 */
final class VideoTaskTest extends IntegrationTestCase
{
    private const DOC = <<<'DOC'
        kind: video
        slug: svinedrengen
        title: Svinedrengen
        youtube: mvvRh8db3HY

        --- questions ---
        1. Hvad sender prinsen til prinsessen?
        * En rose og en nattergal
          En guldkrone og en hest
          Et brev og en ring

        2. Hvad beder prinsen om for kedlen?
          Ti guldmønter
        * Hundrede kys
          Et måltid mad
        DOC;

    private ReadingSessionService $sessions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sessions = new ReadingSessionService();
    }

    private function publish(string $doc = self::DOC): void
    {
        $repo = new ReadingRepository();
        $repo->publish($repo->save((new PassageDocument())->parse($doc)));
    }

    public function testSitsTheNamedFilmWithAllItsQuestions(): void
    {
        $this->publish();
        $this->publish(str_replace(['svinedrengen', 'Svinedrengen'], ['anden', 'Anden'], self::DOC));

        $round = $this->sessions->session($this->sessions->startVideo(null, 'anon-1', 'svinedrengen')['session_id']);

        self::assertCount(2, $round['items']);
        self::assertSame('svinedrengen', $round['passages'][0]['slug']);
        self::assertSame('mvvRh8db3HY', $round['passages'][0]['youtube']);
        self::assertNull($round['remaining_s']);
    }

    public function testTellsTheLearnerAtOnceWhetherAnAnswerWasRight(): void
    {
        $this->publish();
        $sid = $this->sessions->startVideo(null, 'anon-1', 'svinedrengen')['session_id'];

        $answer = $this->sessions->answer($sid, 1, 0);

        self::assertArrayHasKey('is_correct', $answer);
    }

    public function testAFinishedFilmReportsTheScoreWithoutAGrade(): void
    {
        $this->publish();
        $sid = $this->sessions->startVideo(null, 'anon-1', 'svinedrengen')['session_id'];
        $this->sessions->answer($sid, 1, $this->optionIndex($sid, 1, right: true));
        $this->sessions->answer($sid, 2, $this->optionIndex($sid, 2, right: false));

        $this->sessions->submit($sid);
        $result = $this->sessions->result($sid);

        self::assertSame(1, $result['points_scored']);
        self::assertSame(2, $result['points_max']);
        self::assertNull($result['karakter']);
    }

    /** Options are shuffled per session, so an answer is found by its text. */
    private function optionIndex(string $sid, int $position, bool $right): int
    {
        $text = ['En rose og en nattergal', 'Hundrede kys'][$position - 1];
        foreach ($this->sessions->session($sid)['items'][$position - 1]['options'] as $option) {
            if (($option['text'] === $text) === $right) {
                return $option['index'];
            }
        }
        self::fail("Question {$position} has no such option.");
    }

    public function testRefusesAFilmThatIsNotPublished(): void
    {
        $repo = new ReadingRepository();
        $repo->save((new PassageDocument())->parse(self::DOC));

        $this->expectException(RuntimeException::class);

        $this->sessions->startVideo(null, 'anon-1', 'svinedrengen');
    }

    public function testRefusesAReadingPassageAskedForAsAFilm(): void
    {
        $this->publish(<<<'DOC'
            kind: mc
            slug: svinedrengen
            title: Ikke en film

            --- text ---
            En tekst.

            --- questions ---
            1. Hvad?
            * Det
              Det andet
              Det tredje
            DOC);

        $this->expectException(RuntimeException::class);

        $this->sessions->startVideo(null, 'anon-1', 'svinedrengen');
    }
}
