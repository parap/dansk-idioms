<?php declare(strict_types=1);

namespace Dansk\Tests\Integration;

use Dansk\Domain\Reading\PassageDocument;
use Dansk\Domain\Reading\ReadingRepository;
use Dansk\Domain\Reading\ReadingSessionService;
use RuntimeException;

/**
 * A set of verb-form questions, sat as a drill: each answer is checked at once and the
 * round ends in a score, not a grade.
 */
final class VerbDrillTest extends IntegrationTestCase
{
    private const DOC = <<<'DOC'
        kind: verbs
        slug: verber-001-025
        title: Verber 1–25

        --- questions ---
        1. gå (идти) — præteritum
        > navneform gå · datid gik — идти
        * gik
          gået
          går
          gåede

        2. se (видеть) — perfektum participium
        * set
          så
          ser
          seet
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

    public function testSitsTheNamedSetWithAllItsQuestionsAndNoClock(): void
    {
        $this->publish();
        $this->publish(str_replace(['verber-001-025', '1–25'], ['verber-026-050', '26–50'], self::DOC));

        $round = $this->sessions->session($this->sessions->startVerbs(null, 'anon-1', 'verber-001-025')['session_id']);

        self::assertCount(2, $round['items']);
        self::assertSame('verber-001-025', $round['passages'][0]['slug']);
        self::assertNull($round['remaining_s']);
    }

    public function testTellsTheLearnerAtOnceWhetherAnAnswerWasRight(): void
    {
        $this->publish();
        $sid = $this->sessions->startVerbs(null, 'anon-1', 'verber-001-025')['session_id'];

        self::assertArrayHasKey('is_correct', $this->sessions->answer($sid, 1, 0));
    }

    public function testAFinishedSetReportsTheScoreWithoutAGrade(): void
    {
        $this->publish();
        $sid = $this->sessions->startVerbs(null, 'anon-1', 'verber-001-025')['session_id'];
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
        $text = ['gik', 'set'][$position - 1];
        foreach ($this->sessions->session($sid)['items'][$position - 1]['options'] as $option) {
            if (($option['text'] === $text) === $right) {
                return $option['index'];
            }
        }
        self::fail("Question {$position} has no such option.");
    }

    public function testRefusesASetThatIsNotPublished(): void
    {
        (new ReadingRepository())->save((new PassageDocument())->parse(self::DOC));

        $this->expectException(RuntimeException::class);

        $this->sessions->startVerbs(null, 'anon-1', 'verber-001-025');
    }

    public function testRefusesAFilmAskedForAsAVerbSet(): void
    {
        $this->publish(<<<'DOC'
            kind: video
            slug: verber-001-025
            title: En film
            youtube: mvvRh8db3HY

            --- questions ---
            1. Hvad?
            * Det
              Det andet
              Det tredje
            DOC);

        $this->expectException(RuntimeException::class);

        $this->sessions->startVerbs(null, 'anon-1', 'verber-001-025');
    }

    /** The whole verb is shown once a question is answered, and not a moment before. */
    public function testTheNoteArrivesWithTheAnswerAndNotBefore(): void
    {
        $this->publish();
        $sid = $this->sessions->startVerbs(null, 'anon-1', 'verber-001-025');

        self::assertArrayNotHasKey('note', $this->sessions->session($sid['session_id'])['items'][0]);
        self::assertSame('navneform gå · datid gik — идти', $this->sessions->answer($sid['session_id'], 1, 0)['note']);
        self::assertNull($this->sessions->answer($sid['session_id'], 2, 0)['note']);

        $this->sessions->submit($sid['session_id']);
        self::assertSame('navneform gå · datid gik — идти', $this->sessions->result($sid['session_id'])['items'][0]['note']);
    }

    /** Notes reach a set that has already been sat, without disturbing a single answer. */
    public function testNotesAreRefreshedInPlaceOnASetAlreadyServed(): void
    {
        $plain = str_replace("> navneform gå · datid gik — идти\n", '', self::DOC);
        $this->publish($plain);
        $sid = $this->sessions->startVerbs(null, 'anon-1', 'verber-001-025')['session_id'];
        $this->sessions->answer($sid, 1, 0);

        $doc = (new PassageDocument())->parse(self::DOC);
        $changed = (new ReadingRepository())->refreshNotes($doc);

        self::assertSame(1, $changed);
        self::assertNull($this->sessions->answer($sid, 2, 0)['note']);
        $fresh = $this->sessions->startVerbs(null, 'anon-2', 'verber-001-025')['session_id'];
        self::assertSame('navneform gå · datid gik — идти', $this->sessions->answer($fresh, 1, 0)['note']);
    }

    /** A note is matched to its question by the question, so a reworded set keeps its old notes. */
    public function testARefreshLeavesAQuestionThatNoLongerMatches(): void
    {
        $this->publish(str_replace("> navneform gå · datid gik — идти\n", '', self::DOC));

        $doc = (new PassageDocument())->parse(str_replace('gå (идти) — præteritum', 'gå (идти) — datid', self::DOC));

        self::assertSame(0, (new ReadingRepository())->refreshNotes($doc));
    }
}
